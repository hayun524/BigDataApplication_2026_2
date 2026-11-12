-- =====================================================================
-- dbcreate.sql : 테이블 생성 스크립트 (PK, FK, INDEX 포함)
-- 프로젝트   : 서울 날씨와 따릉이 이용량 분석 웹사이트 (빅데이터응용 기말)
-- 작성자     : 김수연 (1. DB 설계)
-- 대상 DBMS  : MariaDB 10.4+ (XAMPP 기본) / 문자셋 utf8mb4
-- 버전       : v2 확정 (11/12) 컬럼 COMMENT 추가 (ERD·phpMyAdmin에 한글 설명 표시)
--              v2 (11/11) raw data 확인 결과 반영 (weather 눈·강수 컬럼, rental_daily INT)
--              (변경 내용은 docs/db-design.md 「변경 이력」 참고)
--
-- 실행 방법
--   phpMyAdmin : 왼쪽에서 team06 DB 선택 → [가져오기] → 이 파일
--   CLI        : mysql -u team06 -p team06 < dbcreate.sql
--   다시 만들 때는 dbdrop.sql → dbcreate.sql 순서로 실행
--
-- 생성 순서 (부모 → 자식)
--   district → station → calendar → weather → rental_daily
--   → user_account → fault_report
-- =====================================================================

-- DB가 없으면 만들고, 있으면 그대로 사용 (IF NOT EXISTS)
--   테스트: team06.* 권한 계정(DB 있음/없음) · root 모두 성공
--   주석 처리하면 DB를 선택하지 않고 실행할 때 1046 No database selected
CREATE DATABASE IF NOT EXISTS team06 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE team06;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. district : 자치구 (9개)
--   - district_id : 행정표준 시군구 코드 5자리 (예: 중구 11140)
--   - life_zone   : 서울시 5대 권역생활권 (2030 서울생활권계획)
--   - daytime_index : 주간인구지수 = 주간인구 / 상주인구 × 100
--                     (2020 인구주택총조사, 통계청) → 소수점 첫째 자리에서 반올림한 정수
--   - district_type : 지수로 자동 계산되는 생성 컬럼
--                     109 초과 업무형 / 100~109 혼합형 / 100 미만 주거형
--                     (서울 전체 지수 108.8 → 반올림 109를 기준선으로 사용)
-- ---------------------------------------------------------------------
CREATE TABLE district (
  district_id    INT           NOT NULL COMMENT '시군구 코드 (행정표준 5자리)',
  district_name  VARCHAR(10)   NOT NULL COMMENT '자치구명',
  life_zone      ENUM('도심권','동북권','서북권','서남권','동남권') NOT NULL COMMENT '권역생활권 (5대 생활권)',
  daytime_index  SMALLINT UNSIGNED NOT NULL COMMENT '주간인구지수 (정수, 2020)',
  district_type  VARCHAR(5) AS (
                   CASE WHEN daytime_index > 109 THEN '업무형'
                        WHEN daytime_index >= 100 THEN '혼합형'
                        ELSE '주거형' END
                 ) PERSISTENT COMMENT '지역 유형: 업무형/혼합형/주거형 (생성 컬럼)',
  CONSTRAINT pk_district PRIMARY KEY (district_id),
  CONSTRAINT uq_district_name UNIQUE (district_name),
  CONSTRAINT ck_daytime_index CHECK (daytime_index > 0),
  INDEX idx_district_zone_type (life_zone, district_type)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci
  COMMENT = '자치구 (생활권, 주간인구지수, 업무/주거 유형)';

-- ---------------------------------------------------------------------
-- 2. station : 따릉이 대여소 (45개)
--   - station_id   : 대여소번호 (예: 102). 이용정보(일별)·대여소 정보 파일과 조인되는 키
--   - station_code : 대여소ID (예: ST-1002), 선택. 5분 단위 승객수 파일을 쓸 때만 필요
--                    ⚠️ ST 뒤 숫자 ≠ 대여소번호 (ST-1002 = 양천구, 1002번 = 강동구)
--                       → 쓰게 되면 대여소 마스터 정보 좌표로 매칭해서 넣기
--   - hangang_park : 한강공원 안 대여소면 공원 이름, 아니면 NULL
--                    (IS NOT NULL → 한강공원 여부, 값 → 공원별 비교)
--   - status       : report.php 트랜잭션에서 '점검중'으로 UPDATE
-- ---------------------------------------------------------------------
CREATE TABLE station (
  station_id      INT              NOT NULL COMMENT '대여소번호 (이용정보와 조인 키)',
  station_code    VARCHAR(10)      NULL COMMENT '대여소ID (ST-xxxx, 선택)',
  station_name    VARCHAR(100)     NOT NULL COMMENT '대여소명',
  district_id     INT              NOT NULL COMMENT '자치구 코드 (FK)',
  address         VARCHAR(200)     NULL COMMENT '주소',
  latitude        DECIMAL(10,7)    NULL COMMENT '위도',
  longitude       DECIMAL(10,7)    NULL COMMENT '경도',
  rack_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '거치대 수 (LCD+QR)',
  installed_date  DATE             NULL COMMENT '설치일',
  hangang_park    ENUM('광나루','잠실','뚝섬','잠원','반포','이촌',
                       '망원','여의도','난지','강서','양화') NULL COMMENT '한강공원 이름 (공원 밖이면 NULL)',
  status          ENUM('정상','점검중') NOT NULL DEFAULT '정상' COMMENT '운영 상태: 정상/점검중',
  CONSTRAINT pk_station PRIMARY KEY (station_id),
  CONSTRAINT uq_station_code UNIQUE (station_code),
  CONSTRAINT fk_station_district FOREIGN KEY (district_id)
    REFERENCES district (district_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_station_district_status (district_id, status),
  INDEX idx_station_hangang (hangang_park)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci
  COMMENT = '따릉이 대여소';

-- ---------------------------------------------------------------------
-- 3. calendar : 날짜 차원 (2024-01-01 ~ 2024-12-31, 366일)
--   - day_of_week : DAYOFWEEK() 기준 1=일 ... 7=토
--   - season      : 3~5 봄 / 6~8 여름 / 9~11 가을 / 12~2 겨울
--   - day_type    : 주말 또는 공휴일이면 '휴일', 아니면 '평일' (생성 컬럼)
-- ---------------------------------------------------------------------
CREATE TABLE calendar (
  cal_date      DATE         NOT NULL COMMENT '날짜',
  year          SMALLINT     NOT NULL COMMENT '연도',
  month         TINYINT      NOT NULL COMMENT '월',
  day           TINYINT      NOT NULL COMMENT '일',
  day_of_week   TINYINT      NOT NULL COMMENT '요일 번호 (1=일 ~ 7=토)',
  day_name      CHAR(1)      NOT NULL COMMENT '요일 이름',
  season        ENUM('봄','여름','가을','겨울') NOT NULL COMMENT '계절',
  is_holiday    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '공휴일 여부 (1=공휴일)',
  holiday_name  VARCHAR(30)  NULL COMMENT '공휴일 이름',
  day_type      VARCHAR(2) AS (
                  CASE WHEN is_holiday = 1 OR day_of_week IN (1, 7)
                       THEN '휴일' ELSE '평일' END
                ) PERSISTENT COMMENT '평일/휴일 (생성 컬럼)',
  CONSTRAINT pk_calendar PRIMARY KEY (cal_date),
  CONSTRAINT ck_month CHECK (month BETWEEN 1 AND 12),
  CONSTRAINT ck_dow CHECK (day_of_week BETWEEN 1 AND 7),
  INDEX idx_calendar_ym (year, month),
  INDEX idx_calendar_season (season),
  INDEX idx_calendar_daytype (day_type)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci
  COMMENT = '날짜 차원 (연·월·계절·요일·공휴일)';

-- ---------------------------------------------------------------------
-- 4. weather : 일별 날씨 (기상청 ASOS 서울 108 지점 일자료)
--   ASOS 일자료 컬럼 → weather 컬럼
--   - 평균/최저/최고기온        → avg_temp / min_temp / max_temp
--   - 일강수량(mm)             → precipitation   (빈칸 = 강수 없음 → 0)
--   - 강수 계속시간(hr)         → rain_hours      (빈칸 = 0)
--   - 평균 상대습도 / 평균 풍속 / 평균 전운량 → avg_humidity / avg_wind / avg_cloud
--   - 합계 3시간 신적설(cm)     → new_snow_cm     그날 새로 내린 눈 (빈칸 = 0)
--   - 일 최심적설(cm)           → snow_depth_cm   땅에 쌓여 있는 눈 (빈칸 = 0)
--   - weather_type : 생성 컬럼
--       새로 내린 눈 > 0, 또는 강수 1mm 이상 & 평균기온 1℃ 이하 → 눈
--       강수 1mm 이상 → 비 / 평균 전운량 6 이상 → 흐림 / 그 외 → 맑음
--       (서울 눈은 0~1℃에서 많이 와서 기존 '0℃ 이하' 기준은 1/6·1/17·11/28을 '비'로 분류)
--   - temp_band    : 생성 컬럼, 평균기온 구간 (영하 / 0~10 / 10~20 / 20~28 / 28+)
-- ---------------------------------------------------------------------
CREATE TABLE weather (
  weather_date   DATE              NOT NULL COMMENT '관측일 (FK)',
  avg_temp       DECIMAL(4,1)      NOT NULL COMMENT '평균기온 (℃)',
  min_temp       DECIMAL(4,1)      NULL COMMENT '최저기온 (℃)',
  max_temp       DECIMAL(4,1)      NULL COMMENT '최고기온 (℃)',
  precipitation  DECIMAL(5,1)      NOT NULL DEFAULT 0 COMMENT '일강수량 (mm, 빈칸=0)',
  rain_hours     DECIMAL(3,1)      NOT NULL DEFAULT 0 COMMENT '강수 계속시간 (hr)',
  avg_humidity   DECIMAL(4,1)      NULL COMMENT '평균 상대습도 (%)',
  avg_wind       DECIMAL(4,1)      NULL COMMENT '평균 풍속 (m/s)',
  avg_cloud      DECIMAL(3,1)      NULL COMMENT '평균 전운량 (1/10)',
  new_snow_cm    DECIMAL(4,1)      NOT NULL DEFAULT 0 COMMENT '신적설 합 (cm, 새로 내린 눈)',
  snow_depth_cm  DECIMAL(4,1)      NOT NULL DEFAULT 0 COMMENT '최심적설 (cm, 쌓인 눈)',
  weather_type   VARCHAR(2) AS (
                   CASE WHEN new_snow_cm > 0
                          OR (precipitation >= 1 AND avg_temp <= 1) THEN '눈'
                        WHEN precipitation >= 1 THEN '비'
                        WHEN avg_cloud >= 6     THEN '흐림'
                        ELSE '맑음' END
                 ) PERSISTENT COMMENT '날씨 구분: 눈/비/흐림/맑음 (생성 컬럼)',
  temp_band      VARCHAR(5) AS (
                   CASE WHEN avg_temp < 0  THEN '영하'
                        WHEN avg_temp < 10 THEN '0~10'
                        WHEN avg_temp < 20 THEN '10~20'
                        WHEN avg_temp < 28 THEN '20~28'
                        ELSE '28+' END
                 ) PERSISTENT COMMENT '기온 구간 (생성 컬럼)',
  CONSTRAINT pk_weather PRIMARY KEY (weather_date),
  CONSTRAINT fk_weather_calendar FOREIGN KEY (weather_date)
    REFERENCES calendar (cal_date)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  -- CHECK : 전처리 실수 방지 (음수 강수·눈, 하루 24시간 넘는 강수 시간 → INSERT 거부)
  CONSTRAINT ck_precip CHECK (precipitation >= 0),
  CONSTRAINT ck_rain_hours CHECK (rain_hours BETWEEN 0 AND 24),
  CONSTRAINT ck_humidity CHECK (avg_humidity IS NULL OR avg_humidity BETWEEN 0 AND 100),
  CONSTRAINT ck_cloud CHECK (avg_cloud IS NULL OR avg_cloud BETWEEN 0 AND 10),
  CONSTRAINT ck_snow CHECK (new_snow_cm >= 0 AND snow_depth_cm >= 0),
  INDEX idx_weather_type (weather_type),
  INDEX idx_weather_temp (avg_temp),
  INDEX idx_weather_band (temp_band)   -- aggregate·rollup에서 GROUP BY temp_band 용
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci
  COMMENT = '일별 날씨 (ASOS 서울 일자료)';

-- ---------------------------------------------------------------------
-- 5. rental_daily : 대여소별 일별 이용 (45 × 366 = 16,470건)
--   원본 '서울시 공공자전거 이용정보(일별)'은 대여구분·성별·연령대별로
--   행이 나뉘어 있으므로 (대여일자, 대여소번호)로 SUM 해서 넣는다.
--   - rent_count       : 이용건수 합
--   - total_minutes    : 이용시간(분) 합
--   - total_distance_m : 이동거리(m) 합
--   - 이용 0건인 날도 0으로 채운 행을 넣기 (이동평균·LAG가 날짜를 건너뛰지 않게)
--   - UNSIGNED 대신 INT + CHECK(>=0) : 전일 대비 증감 (rent_count - LAG(rent_count))이
--     음수일 때 UNSIGNED면 1690 out of range 에러가 나서 signed로 둠
--   - FK → station 은 RESTRICT : 이용 기록 있는 대여소는 삭제 불가
-- ---------------------------------------------------------------------
CREATE TABLE rental_daily (
  station_id        INT            NOT NULL COMMENT '대여소번호 (FK)',
  rent_date         DATE           NOT NULL COMMENT '대여일 (FK)',
  rent_count        INT            NOT NULL DEFAULT 0 COMMENT '이용건수 합',
  total_minutes     INT            NOT NULL DEFAULT 0 COMMENT '이용시간 합 (분)',
  total_distance_m  INT            NOT NULL DEFAULT 0 COMMENT '이동거리 합 (m)',
  CONSTRAINT pk_rental_daily PRIMARY KEY (station_id, rent_date),
  CONSTRAINT ck_rental_nonneg CHECK (rent_count >= 0 AND total_minutes >= 0
                                     AND total_distance_m >= 0),
  CONSTRAINT fk_rental_station FOREIGN KEY (station_id)
    REFERENCES station (station_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_rental_calendar FOREIGN KEY (rent_date)
    REFERENCES calendar (cal_date)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_rental_date (rent_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci
  COMMENT = '대여소별 일별 이용량';

-- ---------------------------------------------------------------------
-- 6. user_account : 로그인 계정 (login.php, 세션)
--   - password_hash : PHP password_hash() 결과 (bcrypt 60자, 여유 있게 255)
-- ---------------------------------------------------------------------
CREATE TABLE user_account (
  user_id        INT           NOT NULL AUTO_INCREMENT COMMENT '사용자 번호',
  login_id       VARCHAR(30)   NOT NULL COMMENT '로그인 아이디',
  password_hash  VARCHAR(255)  NOT NULL COMMENT '비밀번호 해시 (bcrypt)',
  user_name      VARCHAR(30)   NOT NULL COMMENT '이름',
  role           ENUM('admin','user') NOT NULL DEFAULT 'user' COMMENT '권한: admin/user',
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '가입 일시',
  CONSTRAINT pk_user_account PRIMARY KEY (user_id),
  CONSTRAINT uq_login_id UNIQUE (login_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci
  COMMENT = '사용자 계정';

-- ---------------------------------------------------------------------
-- 7. fault_report : 고장 신고 (report.php 트랜잭션)
--   INSERT fault_report + UPDATE station.status='점검중' 을 한 트랜잭션으로
--   - fault_type 값은 3번(정하윤) 확인 후 확정
-- ---------------------------------------------------------------------
CREATE TABLE fault_report (
  report_id       INT            NOT NULL AUTO_INCREMENT COMMENT '신고 번호',
  station_id      INT            NOT NULL COMMENT '대여소번호 (FK)',
  user_id         INT            NOT NULL COMMENT '신고자 (FK)',
  fault_type      ENUM('타이어','브레이크','체인','페달','안장','단말기','거치대','기타') NOT NULL COMMENT '고장 유형',
  description     VARCHAR(500)   NULL COMMENT '상세 내용',
  reported_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '신고 일시',
  process_status  ENUM('접수','처리중','완료') NOT NULL DEFAULT '접수' COMMENT '처리 상태: 접수/처리중/완료',
  CONSTRAINT pk_fault_report PRIMARY KEY (report_id),
  CONSTRAINT fk_report_station FOREIGN KEY (station_id)
    REFERENCES station (station_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_report_user FOREIGN KEY (user_id)
    REFERENCES user_account (user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_report_station_time (station_id, reported_at),
  INDEX idx_report_status (process_status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci
  COMMENT = '고장 신고';

-- =====================================================================
-- dbcreate.sql : 테이블 생성 스크립트 (PK, FK, INDEX 포함)
-- 프로젝트   : 서울 날씨와 따릉이 이용량 분석 웹사이트 (빅데이터응용 기말)
-- 작성자     : 김수연 (1. DB 설계)
-- 대상 DBMS  : MariaDB 10.4+ (XAMPP 기본) / 문자셋 utf8mb4
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

-- 평가 서버에서 CREATE DATABASE 권한이 있을 때만 아래 2줄 주석 해제
-- CREATE DATABASE IF NOT EXISTS team06 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
-- USE team06;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. district : 자치구 (9개)
--   - district_id : 행정표준 시군구 코드 5자리 (예: 중구 11140)
--   - life_zone   : 서울시 5대 권역생활권 (2030 서울생활권계획)
--   - daytime_index : 주간인구지수 = 주간인구 / 상주인구 × 100
--                     (2020 인구주택총조사, 통계청)
--   - district_type : 지수로 자동 계산되는 생성 컬럼
--                     109 초과 업무형 / 100~109 혼합형 / 100 미만 주거형
--                     (서울 전체 지수 108.8 → 반올림 109를 기준선으로 사용)
-- ---------------------------------------------------------------------
CREATE TABLE district (
  district_id    INT           NOT NULL,
  district_name  VARCHAR(10)   NOT NULL,
  life_zone      ENUM('도심권','동북권','서북권','서남권','동남권') NOT NULL,
  daytime_index  DECIMAL(5,1)  NOT NULL,
  district_type  VARCHAR(5) AS (
                   CASE WHEN daytime_index > 109 THEN '업무형'
                        WHEN daytime_index >= 100 THEN '혼합형'
                        ELSE '주거형' END
                 ) PERSISTENT,
  CONSTRAINT pk_district PRIMARY KEY (district_id),
  CONSTRAINT uq_district_name UNIQUE (district_name),
  CONSTRAINT ck_daytime_index CHECK (daytime_index > 0),
  INDEX idx_district_zone_type (life_zone, district_type)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci
  COMMENT = '자치구 (생활권, 주간인구지수, 업무/주거 유형)';

-- ---------------------------------------------------------------------
-- 2. station : 따릉이 대여소 (45개)
--   - station_id   : 대여소번호 (예: 102). 이용정보(일별) 파일과 조인되는 키
--   - station_code : 대여소ID (예: ST-4). 실시간 API·마스터 정보용, 선택
--   - hangang_park : 한강공원 안 대여소면 공원 이름, 아니면 NULL
--                    (IS NOT NULL → 한강공원 여부, 값 → 공원별 비교)
--   - status       : report.php 트랜잭션에서 '점검중'으로 UPDATE
-- ---------------------------------------------------------------------
CREATE TABLE station (
  station_id      INT              NOT NULL,
  station_code    VARCHAR(10)      NULL,
  station_name    VARCHAR(100)     NOT NULL,
  district_id     INT              NOT NULL,
  address         VARCHAR(200)     NULL,
  latitude        DECIMAL(10,7)    NULL,
  longitude       DECIMAL(10,7)    NULL,
  rack_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  installed_date  DATE             NULL,
  hangang_park    ENUM('광나루','잠실','뚝섬','잠원','반포','이촌',
                       '망원','여의도','난지','강서','양화') NULL,
  status          ENUM('정상','점검중') NOT NULL DEFAULT '정상',
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
  cal_date      DATE         NOT NULL,
  year          SMALLINT     NOT NULL,
  month         TINYINT      NOT NULL,
  day           TINYINT      NOT NULL,
  day_of_week   TINYINT      NOT NULL,
  day_name      CHAR(1)      NOT NULL,
  season        ENUM('봄','여름','가을','겨울') NOT NULL,
  is_holiday    TINYINT(1)   NOT NULL DEFAULT 0,
  holiday_name  VARCHAR(30)  NULL,
  day_type      VARCHAR(2) AS (
                  CASE WHEN is_holiday = 1 OR day_of_week IN (1, 7)
                       THEN '휴일' ELSE '평일' END
                ) PERSISTENT,
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
--   - precipitation : 일강수량(mm). ASOS 원본에서 빈칸 = 강수 없음 → 0 으로 넣기
--   - weather_type  : 생성 컬럼 (기준은 팀 확정 전 초안)
--                     강수 1mm 이상 & 평균기온 0℃ 이하 → 눈
--                     강수 1mm 이상 → 비
--                     평균 전운량 6 이상 → 흐림 (구름많음 포함)
--                     그 외 → 맑음
-- ---------------------------------------------------------------------
CREATE TABLE weather (
  weather_date   DATE          NOT NULL,
  avg_temp       DECIMAL(4,1)  NOT NULL,
  min_temp       DECIMAL(4,1)  NULL,
  max_temp       DECIMAL(4,1)  NULL,
  precipitation  DECIMAL(5,1)  NOT NULL DEFAULT 0,
  avg_humidity   DECIMAL(4,1)  NULL,
  avg_wind       DECIMAL(4,1)  NULL,
  avg_cloud      DECIMAL(3,1)  NULL,
  weather_type   VARCHAR(2) AS (
                   CASE WHEN precipitation >= 1 AND avg_temp <= 0 THEN '눈'
                        WHEN precipitation >= 1 THEN '비'
                        WHEN avg_cloud >= 6 THEN '흐림'
                        ELSE '맑음' END
                 ) PERSISTENT,
  CONSTRAINT pk_weather PRIMARY KEY (weather_date),
  CONSTRAINT fk_weather_calendar FOREIGN KEY (weather_date)
    REFERENCES calendar (cal_date)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT ck_precip CHECK (precipitation >= 0),
  CONSTRAINT ck_humidity CHECK (avg_humidity IS NULL OR avg_humidity BETWEEN 0 AND 100),
  CONSTRAINT ck_cloud CHECK (avg_cloud IS NULL OR avg_cloud BETWEEN 0 AND 10),
  INDEX idx_weather_type (weather_type),
  INDEX idx_weather_temp (avg_temp)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci
  COMMENT = '일별 날씨 (ASOS 서울)';

-- ---------------------------------------------------------------------
-- 5. rental_daily : 대여소별 일별 이용 (약 45 × 366 = 16,470건)
--   원본 '서울시 공공자전거 이용정보(일별)'은 대여구분·성별·연령대별로
--   행이 나뉘어 있으므로 (대여일자, 대여소번호)로 SUM 해서 넣는다.
--   - rent_count       : 이용건수 합
--   - total_minutes    : 이용시간(분) 합
--   - total_distance_m : 이동거리(M) 합
--   - FK → station 은 RESTRICT : 이용 기록 있는 대여소는 삭제 불가
-- ---------------------------------------------------------------------
CREATE TABLE rental_daily (
  station_id        INT            NOT NULL,
  rent_date         DATE           NOT NULL,
  rent_count        INT UNSIGNED   NOT NULL DEFAULT 0,
  total_minutes     INT UNSIGNED   NOT NULL DEFAULT 0,
  total_distance_m  DECIMAL(12,2)  NOT NULL DEFAULT 0,
  CONSTRAINT pk_rental_daily PRIMARY KEY (station_id, rent_date),
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
  user_id        INT           NOT NULL AUTO_INCREMENT,
  login_id       VARCHAR(30)   NOT NULL,
  password_hash  VARCHAR(255)  NOT NULL,
  user_name      VARCHAR(30)   NOT NULL,
  role           ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
  report_id       INT            NOT NULL AUTO_INCREMENT,
  station_id      INT            NOT NULL,
  user_id         INT            NOT NULL,
  fault_type      ENUM('타이어','브레이크','체인','페달','안장','단말기','거치대','기타') NOT NULL,
  description     VARCHAR(500)   NULL,
  reported_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  process_status  ENUM('접수','처리중','완료') NOT NULL DEFAULT '접수',
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

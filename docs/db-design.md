# 1. DB 설계 파트 (김수연)

## 폴더 구조

```
team06/
├─ sql/
│  ├─ dbcreate.sql            ← 제출: 테이블 7개 + PK·FK·INDEX
│  ├─ dbdrop.sql              ← 제출: 자식→부모 순서로 DROP
│  ├─ data/
│  │  ├─ district_seed.sql    ← 실제 데이터 9건 → 2번 dbinsert.sql 맨 앞에 합치기
│  │  └─ calendar_2024.sql    ← 실제 데이터 366건 (2024 공휴일 19일 포함) → 2번에게
│  └─ dev/dev_sample.sql      ← 테스트용 가짜 대여소 18개 (제출 금지)
├─ web/
│  ├─ station_list.php        ← 제출: 대여소 검색 페이지
│  ├─ config.php, header.php  ← 로컬 테스트용 임시본. 6번 파일 나오면 교체 (GitHub 올리지 않기)
├─ tools/
│  ├─ gen_calendar.py         ← calendar_2024.sql 생성기
│  └─ test_db.sh              ← create→seed→drop 2회 반복 테스트
└─ docs/station_list_screenshot.png
```

## 맥: 설치_맥.command 더블클릭 한 번이면 아래 1~3이 자동

## 로컬 실행 순서 (XAMPP)

1. phpMyAdmin에서 `team06` DB·계정 만들기 (비번은 config.php와 맞추기)
2. `team06` 선택 → 가져오기: `dbcreate.sql` → `district_seed.sql` → `calendar_2024.sql` → `dev_sample.sql`
3. `web/` 파일을 `htdocs/team06/`에 복사 → `http://localhost/team06/station_list.php`
4. 다시 만들 때: `dbdrop.sql` → 2번부터 반복

## 테스트 결과 (MariaDB 10.11, PHP 8.3)

- create → seed → drop 2회 반복: 오류 없음, drop 후 남은 테이블 0
- 이용기록 있는 대여소 DELETE → 1451 에러로 막힘 / 기록 없는 대여소는 삭제됨
- 없는 자치구 FK, 같은 날 중복(PK), 달력 밖 날짜(FK), 잘못된 상태값(ENUM) → 전부 막힘
- report.php 트랜잭션 (INSERT 신고 + UPDATE 점검중) 정상
- WITH ROLLUP, RANK() OVER, LAG() 동작 확인
- station_list.php: 생활권·자치구·상태·한강공원·이름·정렬 조합 검색 정상, SQL 인젝션·XSS 입력 무력화 확인

## 데이터 출처

| 항목 | 출처 |
|---|---|
| 주간인구지수 | 통계청 2020 인구주택총조사 「성별/연령별 상주(야간)·주간 인구-시군구」 (KOSIS). 서울시 파일: 공공데이터포털 15124242 「서울특별시_자치구별 연령별 주간 야간 인구」 |
| 서울 전체 주간인구지수 108.8 | 통계청 2020 인구주택총조사 표본 결과 보도자료 (2021.11.29) |
| 5대 권역생활권 | 서울시 2030 서울생활권계획 (urban.seoul.go.kr) |
| 시군구 코드 | 행정표준코드 (중구 11140 … 강동구 11740) |
| 한강공원 11곳 | 미래한강본부 (hangang.seoul.go.kr) |
| 대여소 정보 | 서울 열린데이터광장 OA-13252 「공공자전거 대여소 정보」 (24.12월 기준 xlsx) |
| 대여·반납 승객수 | 서울 열린데이터광장 「따릉이 대여소별 대여/반납 승객수 정보(5분 단위)」: 기준_날짜, 집계_기준(출발시간/도착시간), 기준_시간대(HHMM), 시작/종료_대여소_ID(ST-xxx), 대여소명, 전체_건수, 전체_이용_분, 전체_이용_거리(m) |
| 대여소 마스터 정보 | 서울 열린데이터광장 「공공자전거 대여소 마스터 정보」: 대여소_ID(ST-xxx), 주소, 위도, 경도 → ST-ID ↔ 대여소번호 좌표 매칭용 |
| 날씨 | 기상자료개방포털 ASOS **시간자료**, 서울(108) 지점 → 하루 단위로 집계 |
| 고장신고 | 서울 열린데이터광장 OA-15644 「따릉이 고장신고 내역」: 자전거번호, 등록일시, 고장구분 (대여소 정보 없음) |
| 2024 공휴일 | 관공서의 공휴일에 관한 규정 + 4/10 총선, 10/1 국군의 날 임시공휴일 |

## 팀원에게 전달할 것

**2번 정서진 (데이터)**
- `dbinsert.sql` 맨 위에 `SET NAMES utf8mb4;` 필수. 없으면 CLI로 넣을 때 한글 ENUM 값이 'Data truncated' 에러남
- `district_seed.sql`, `calendar_2024.sql`을 dbinsert.sql 앞부분에 합치기 (순서: district → calendar → station → weather → rental_daily …)
- `station.station_id` = **대여소번호(숫자)**, `station.station_code` = **ST-ID** → 샘플 45개는 둘 다 필수
  - ⚠️ ST 뒤 숫자 ≠ 대여소번호 (ST-1002 = 양천구 목1동, 1002번 = 강동구 해공공원) → 마스터 정보 좌표로 매칭
- 대여·반납 승객수 파일은 이용 1건이 `집계_기준` '출발시간'/'도착시간'으로 **2번** 들어 있음
  - rent_count = '출발시간' 행만, 시작_대여소 기준 SUM / return_count = '도착시간' 행만, 종료_대여소 기준 SUM ('X' 제외)
- 이용이 0건인 날은 원본에 행이 없음 → **0으로 채운 행을 넣어야** 5번 이동평균·LAG가 날짜를 건너뛰지 않음
- ASOS 시간자료 → 하루 단위: 기온 평균·최저·최고 / 강수량 합 (빈칸 = 0) / 강수 기록 시간 수 / 7~9시·17~19시 강수 여부 / 습도·풍속·전운량 평균 / 3시간신적설 합 / 적설 **최대** (누적값이라 합계 금지)
- 강수량 '0.0' = 0.1mm 미만 흔적 강수 (빈칸과 다름) → rain_hours에는 포함
- 날씨 구분은 생성 컬럼이 자동 계산: 새로 내린 눈 > 0 (또는 강수 1mm↑ & 평균 1℃↓) 눈 / 강수 1mm↑ 비 / 전운량 6↑ 흐림 / 나머지 맑음

**3번 정하윤 (CRUD)**
- `station.status` 값은 `'정상'`, `'점검중'` 두 개만 허용
- 이용기록·신고가 있는 대여소 DELETE 시 mysqli 예외 코드 **1451** → 이걸 잡아서 "이용 기록이 있어 삭제할 수 없습니다" 안내
- `fault_type` 값 확정해 주기 (현재: 타이어·브레이크·체인·페달·안장·단말기·거치대·기타). 실제 고장신고 데이터는 자전거 단위라 대여소 정보가 없음

**4번 유지민 / 5번 노경윤 (분석)**
- 생성 컬럼 바로 GROUP BY 가능: `district.district_type`(업무형/주거형), `calendar.day_type`(평일/휴일), `weather.weather_type`, `weather.temp_band`(기온 구간)
- 순유입 = `return_count - rent_count` (+면 자전거가 몰리는 대여소). 컬럼이 signed INT라 음수도 에러 없음
- 드릴다운 계층: 생활권 → 자치구 → 대여소, 계절 → 월 → 일
- 도심권은 대여소 5개, 나머지 생활권은 10개 → 생활권 비교는 SUM 대신 **AVG 또는 대여소당 이용량**

**6번 왕은서 (공통)**
- station_list.php는 config.php의 `$conn` (mysqli 객체)를 사용 → 변수명 맞춰주기
- 평가 서버에서 CREATE DATABASE 권한이 있는지 확인 (dbcreate.sql 맨 위 2줄 주석 해제 여부)

## 참고: 업무형 기준 109
서울 전체 주간인구지수가 108.8이라 "서울 평균 이상 = 109 초과"로 반올림해 쓴 것. 보고서에는 108.8 출처를 같이 적으면 좋음.
선정된 9개 구 중 100~109(혼합형)에 들어가는 구는 없음.

## v2 변경 이유 (근거 데이터)

### weather

| 수정 | 이유 | 근거 |
|---|---|---|
| `new_snow_cm` 추가 + 눈 판정 수정 | 기존 규칙(강수 1mm↑ & 평균 0℃↓)은 0~1℃ 눈을 놓침 | 1월 눈 온 날 4일 중 3일 오분류: 1/6(0.8℃, 눈 6cm)·1/17(0.3℃, 2.4cm) → '비', 1/22(강수 0.2mm, 2.3cm) → 눈 아님 |
| `snow_depth_cm` 추가 | 눈이 그친 뒤에도 쌓인 눈 때문에 이용 감소 | 1/7 '맑음'인데 쌓인 눈 5.6cm → 없으면 이상치로 보임 |
| `rain_hours` 추가 | 같은 강수량이라도 오래 오면 영향이 큼 | 일 합계만으로는 1시간 소나기 = 하루 종일 비 |
| `commute_rain` 추가 | 비가 언제 왔는지가 중요 | 4/1 이용: 3시 502건 vs 8시 13,289건 vs 18시 18,574건 |
| `temp_band` 추가 | 기온은 연속값이라 GROUP BY가 어려움 | 페이지마다 구간이 달라지는 것 방지, 화이트리스트에 컬럼명 하나로 등록 가능 |
| CHECK 3개 | 전처리 실수 방지 | rain_hours ≤ 24, commute_rain ∈ {0,1}, 눈 ≥ 0 (테스트: rain_hours = 30 → 거부) |
| INDEX `idx_weather_band` | GROUP BY·WHERE에 자주 사용 | aggregate·rollup 페이지 |

### rental_daily

| 수정 | 이유 | 근거 |
|---|---|---|
| rent_count = 출발 기준 명시 | 원본에 이용 1건이 출발/도착으로 2번 기록 | 4/1: 출발 153,896 + 도착 153,896 → 다 더하면 2배 |
| `return_count` 추가 | 순유입(반납 − 대여)으로 업무형/주거형 분류를 데이터로 확인 | 출퇴근 피크(8시·18시) = 자전거 이동 |
| UNSIGNED → INT + CHECK(≥0) | UNSIGNED끼리 빼서 음수가 되면 에러 | 테스트: 80 − 90 → ERROR 1690 |

### station

| 수정 | 이유 | 근거 |
|---|---|---|
| `station_code` 주석 | 승객수 파일의 키가 ST-ID | ST-1002 = 양천구 목1동 ≠ 대여소번호 1002 = 강동구 해공공원 → 숫자로 맞추면 조용히 틀림 |

## 변경 이력

| 버전 | 날짜 | 내용 | 이유 |
|---|---|---|---|
| v1 | 11/10 | 테이블 7개 최초 작성 | — |
| v2 | 11/10 | weather: rain_hours·commute_rain·new_snow_cm·snow_depth_cm·temp_band 추가, weather_type 눈 판정 수정 | 원본이 시간자료 / 0~1℃ 눈 오분류 (1/6·1/17·1/22) |
| v2 | 11/10 | rental_daily: return_count 추가, rent_count = 출발 기준 명시, 4개 컬럼 INT + CHECK(>=0) | 원본에 출발/도착 2번 기록 / UNSIGNED 뺄셈 1690 에러 |
| v2 | 11/10 | station: station_code(ST-ID) 설명 수정 (구조 동일) | 이용 데이터 키가 ST-ID |
| 대기 | ~11/11 | fault_report 처리 방식 (3번), 시간대 테이블 추가 여부 (4·5번) | 팀 답변 후 반영 |

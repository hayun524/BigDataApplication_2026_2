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
| 일별 이용정보 | 서울 열린데이터광장 「공공자전거 이용정보(일별)」: 대여일자, 대여소번호, 대여구분코드, 성별, 연령대코드, 이용건수, 운동량, 탄소량, 이동거리(M), 이용시간(분) → **rental_daily 원본** (data/filter_daily.py) |
| (참고) 대여·반납 승객수 | 「따릉이 대여소별 대여/반납 승객수 정보(5분 단위)」: 집계_기준(출발/도착)으로 이용 1건이 2번 기록, 대여소 ID = ST-xxx → **현재 미사용** (시간대 분석을 하게 되면 사용) |
| 날씨 | 기상자료개방포털 ASOS 일자료, 서울(108) 지점 |
| 고장신고 | 서울 열린데이터광장 OA-15644 「따릉이 고장신고 내역」: 자전거번호, 등록일시, 고장구분 (대여소 정보 없음) |
| 2024 공휴일 | 관공서의 공휴일에 관한 규정 + 4/10 총선, 10/1 국군의 날 임시공휴일 |

## 팀원에게 전달할 것

**2번 정서진 (데이터)**
- `dbinsert.sql` 맨 위에 `SET NAMES utf8mb4;` 필수. 없으면 CLI로 넣을 때 한글 ENUM 값이 'Data truncated' 에러남
- `district_seed.sql`, `calendar_2024.sql`을 dbinsert.sql 앞부분에 합치기 (순서: district → calendar → station → weather → rental_daily …)
- `station.station_id` = **대여소번호(숫자)**. 일별 이용정보와 이 번호로 조인됨. `station_code`(ST-ID)는 선택
  - ⚠️ 5분 단위 승객수 파일을 쓰게 되면: ST 뒤 숫자 ≠ 대여소번호 (ST-1002 = 양천구, 1002번 = 강동구) → 마스터 정보 좌표로 매칭
- 일별 이용정보는 성별·연령대·대여구분별로 행이 쪼개져 있음 → `(대여일자, 대여소번호)`로 SUM 해서 rental_daily 1행으로 (✅ 11/10 dbinsert.sql 반영됨)
- 이용이 0건인 날은 원본에 행이 없음 → **0으로 채운 행을 넣어야** 5번 이동평균·LAG가 날짜를 건너뛰지 않음
- ASOS 일자료에서 weather에 **3개 컬럼 추가로 채우기** (빈칸 = 0)
  - `강수 계속시간(hr)` → `rain_hours`
  - `합계 3시간 신적설(cm)` → `new_snow_cm` (그날 새로 내린 눈)
  - `일 최심적설(cm)` → `snow_depth_cm` (쌓여 있는 눈)
- 날씨 구분은 생성 컬럼이 자동 계산: 새로 내린 눈 > 0 (또는 강수 1mm↑ & 평균 1℃↓) 눈 / 강수 1mm↑ 비 / 전운량 6↑ 흐림 / 나머지 맑음

**3번 정하윤 (CRUD)**
- `station.status` 값은 `'정상'`, `'점검중'` 두 개만 허용
- 이용기록·신고가 있는 대여소 DELETE 시 mysqli 예외 코드 **1451** → 이걸 잡아서 "이용 기록이 있어 삭제할 수 없습니다" 안내
- `fault_type` 값 확정해 주기 (현재: 타이어·브레이크·체인·페달·안장·단말기·거치대·기타). 실제 고장신고 데이터는 자전거 단위라 대여소 정보가 없음

**4번 유지민 / 5번 노경윤 (분석)**
- 생성 컬럼 바로 GROUP BY 가능: `district.district_type`(업무형/주거형), `calendar.day_type`(평일/휴일), `weather.weather_type`, `weather.temp_band`(기온 구간)
- 전일 대비 증감 `rent_count - LAG(rent_count) OVER (...)`: 컬럼이 signed INT라 음수여도 에러 없음 (UNSIGNED였으면 1690 에러)
- 드릴다운 계층: 생활권 → 자치구 → 대여소, 계절 → 월 → 일
- 도심권은 대여소 5개, 나머지 생활권은 10개 → 생활권 비교는 SUM 대신 **AVG 또는 대여소당 이용량**

**6번 왕은서 (공통)**
- station_list.php는 config.php의 `$conn` (mysqli 객체)를 사용 → 변수명 맞춰주기
- dbcreate.sql 맨 위 `CREATE DATABASE IF NOT EXISTS team06; USE team06;` 활성화함 → team06 계정(team06.* 권한)·root 모두 설치 성공 확인. 계정·DB 이름만 team06으로 맞추면 됨

## 참고: 업무형 기준 109
서울 전체 주간인구지수가 108.8이라 "서울 평균 이상 = 109 초과"로 반올림해 쓴 것. 보고서에는 108.8 출처를 같이 적으면 좋음.
선정된 9개 구 중 100~109(혼합형)에 들어가는 구는 없음.

## v2 변경 이유 (근거 데이터)

### weather

| 수정 | 이유 | 근거 |
|---|---|---|
| 눈 판정: 평균 0℃ 이하 → 1℃ 이하 + `new_snow_cm` | 서울 눈은 0~1℃에서 많이 옴 | 기존 기준: 1년 중 눈 4일 / 수정 기준: 8일 (1/6 0.7℃·1/17 0.6℃·11/28 0.8℃가 '비' → '눈') |
| `new_snow_cm` 추가 | 강수량이 1mm 안 돼도 눈이 쌓이는 날이 있음 | 1/22: 강수 0.2mm인데 눈 2.3cm → 기온·강수 규칙만으로는 '맑음' |
| `snow_depth_cm` 추가 | 눈이 그친 뒤에도 쌓인 눈 때문에 이용 감소 | 1/7: 새 눈 없음, 쌓인 눈 5.6cm → 없으면 이상치로 보임 |
| `rain_hours` 추가 | 같은 강수량이라도 오래 오면 영향이 큼 | 일강수량만으로는 1시간 소나기 = 하루 종일 비 |
| `temp_band` 추가 | 기온은 연속값이라 GROUP BY가 어려움 | 페이지마다 구간이 달라지는 것 방지, 화이트리스트에 컬럼명 하나로 등록 가능 |
| CHECK 추가 | 전처리 실수 방지 | rain_hours 0~24, 눈 ≥ 0 |
| INDEX `idx_weather_band` | GROUP BY·WHERE에 자주 사용 | aggregate·rollup 페이지 |

### rental_daily

| 수정 | 이유 | 근거 |
|---|---|---|
| UNSIGNED → INT + CHECK(≥0) | 전일 대비 증감(LAG)이 음수일 때 UNSIGNED면 에러 | 테스트: 90 → 80, `c - LAG(c)` → ERROR 1690 / INT면 -10 정상 |

### station

| 수정 | 이유 | 근거 |
|---|---|---|
| `station_code` 주석 | 5분 단위 파일을 쓸 경우를 대비한 경고 | ST-1002 = 양천구 목1동 ≠ 대여소번호 1002 = 강동구 해공공원 |

### 검증 (MariaDB 10.11, 11/11)
- dbcreate → dbinsert.sql(정서진 11/10) → dbdrop 2회: 오류 없음 / rental_daily 16,470행, 대여소 45개
- 평일 평균 대여 (비 vs 맑음): 업무형 55.7 vs 92.9, 주거형 49.3 vs 76.1

## 변경 이력

| 버전 | 날짜 | 내용 | 이유 |
|---|---|---|---|
| v1 | 11/10 | 테이블 7개 최초 작성 | — |
| v2 | 11/11 | weather: rain_hours·new_snow_cm·snow_depth_cm·temp_band 추가, weather_type 눈 판정 수정 | 0~1℃ 눈 오분류 (눈 4일 → 8일), 1/22처럼 강수 1mm 미만 눈 |
| v2 | 11/11 | rental_daily: 숫자 컬럼 INT + CHECK(>=0) | LAG 증감 음수 시 UNSIGNED 1690 에러 |
| v2 | 11/11 | station: station_code(ST-ID) 설명 수정 (구조 동일) | 5분 단위 파일 사용 시 ST-ID ≠ 대여소번호 |
| v2 | 11/11 | dbcreate.sql 맨 위 CREATE DATABASE·USE 주석 해제 | IF NOT EXISTS라 DB 유무·권한 경우 모두 설치 성공 (DB 미선택 시 1046 방지) |
| 대기 | ~11/11 | fault_report 처리 방식 (3번), 시간대 분석 여부 (4·5번) | 팀 답변 후 반영 |

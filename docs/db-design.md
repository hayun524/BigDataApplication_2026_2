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
| 일별 이용정보 | 서울 열린데이터광장 「공공자전거 이용정보(일별)」: 대여일자, 대여소번호, 대여구분코드, 성별, 연령대코드, 이용건수, 운동량, 탄소량, 이동거리(M), 이용시간(분) |
| 날씨 | 기상자료개방포털 ASOS 일자료, 서울(108) 지점 |
| 고장신고 | 서울 열린데이터광장 OA-15644 「따릉이 고장신고 내역」: 자전거번호, 등록일시, 고장구분 (대여소 정보 없음) |
| 2024 공휴일 | 관공서의 공휴일에 관한 규정 + 4/10 총선, 10/1 국군의 날 임시공휴일 |

## 팀원에게 전달할 것

**2번 정서진 (데이터)**
- `dbinsert.sql` 맨 위에 `SET NAMES utf8mb4;` 필수. 없으면 CLI로 넣을 때 한글 ENUM 값이 'Data truncated' 에러남
- `district_seed.sql`, `calendar_2024.sql`을 dbinsert.sql 앞부분에 합치기 (순서: district → calendar → station → weather → rental_daily …)
- `station.station_id` = **대여소번호(숫자)**. 일별 이용정보와 이 번호로 조인됨. ST-xxx는 `station_code`에 (선택)
- 일별 이용정보는 성별·연령대·대여구분별로 행이 쪼개져 있음 → `(대여일자, 대여소번호)`로 SUM 해서 rental_daily 1행으로
- 이용이 0건인 날은 원본에 행이 없음 → **0으로 채운 행을 넣어야** 5번 이동평균·LAG가 날짜를 건너뛰지 않음
- ASOS 일강수량 빈칸 = 강수 없음 → 0 으로. `avg_cloud`(평균 전운량) 컬럼도 같이 뽑아주기 (흐림 판정용)
- 날씨 구분 기준 초안: 강수 1mm 이상이면 비(평균기온 0℃ 이하면 눈), 전운량 6 이상 흐림, 나머지 맑음 → 의견 주기

**3번 정하윤 (CRUD)**
- `station.status` 값은 `'정상'`, `'점검중'` 두 개만 허용
- 이용기록·신고가 있는 대여소 DELETE 시 mysqli 예외 코드 **1451** → 이걸 잡아서 "이용 기록이 있어 삭제할 수 없습니다" 안내
- `fault_type` 값 확정해 주기 (현재: 타이어·브레이크·체인·페달·안장·단말기·거치대·기타). 실제 고장신고 데이터는 자전거 단위라 대여소 정보가 없음

**4번 유지민 / 5번 노경윤 (분석)**
- 생성 컬럼 바로 GROUP BY 가능: `district.district_type`(업무형/주거형), `calendar.day_type`(평일/휴일), `weather.weather_type`
- 드릴다운 계층: 생활권 → 자치구 → 대여소, 계절 → 월 → 일
- 도심권은 대여소 5개, 나머지 생활권은 10개 → 생활권 비교는 SUM 대신 **AVG 또는 대여소당 이용량**

**6번 왕은서 (공통)**
- station_list.php는 config.php의 `$conn` (mysqli 객체)를 사용 → 변수명 맞춰주기
- 평가 서버에서 CREATE DATABASE 권한이 있는지 확인 (dbcreate.sql 맨 위 2줄 주석 해제 여부)

## 참고: 업무형 기준 109
서울 전체 주간인구지수가 108.8이라 "서울 평균 이상 = 109 초과"로 반올림해 쓴 것. 보고서에는 108.8 출처를 같이 적으면 좋음.
선정된 9개 구 중 100~109(혼합형)에 들어가는 구는 없음.

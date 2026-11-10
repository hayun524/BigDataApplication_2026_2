# 빅데이터응용 기말 프로젝트 — 날씨와 따릉이 이용량

서울 날씨(기온·강수량 등)가 따릉이 이용량에 주는 영향을 분석하는 웹사이트 (PHP + MariaDB, XAMPP)

## 폴더 구조
이 레포 = 제출용 `htdocs/teamXX` 폴더 그대로

```
/                PHP 페이지 (config.php, header.php, index.php, station_list.php ...)
sql/             dbcreate.sql, dbdrop.sql, dbinsert.sql, dbdump.sql
data/            데이터 변환 스크립트 (원본 CSV는 data/raw/ 에 두고 커밋 안 함)
docs/            ERD, 코드 구조도, 보고서 자료
assets/          CSS, JS, Chart.js 등 (CDN 대신 파일로 포함)
```

## 담당
| 번호 | 역할 | 담당 | 파일 |
|---|---|---|---|
| 1 | DB 설계 | 김수연 | sql/dbcreate.sql, sql/dbdrop.sql, sql/dbdump.sql, docs/ERD, station_list.php |
| 2 | 데이터 | 정서진 | sql/dbinsert.sql, data/ 변환 스크립트, weather_manage.php |
| 3 | CRUD 웹 | 정하윤 | station_form.php, report.php |
| 4 | 분석 A | 유지민 | aggregate.php, rollup.php |
| 5 | 분석 B | 노경윤 | ranking.php, window.php |
| 6 | 공통·문서 | 왕은서 | config.php, header.php, login.php, logout.php, index.php |

## 작업 규칙
1. **main에 직접 push 금지.** 각자 브랜치에서 작업 → Pull Request → main에 merge
   - 브랜치 이름: `feat/역할-이름` (예: `feat/db-suyeon`)
2. 작업 시작 전 `git pull origin main`으로 최신화
3. **자기 파일만 수정.** 남의 파일 고칠 일이 있으면 단톡에 먼저 말하기
4. 모든 파일 상단과 주요 함수에 `// 작성자: 이름` 주석 (SQL은 `-- 작성자: 이름`)
5. DB 연결은 `config.php`만 사용, 사용자 입력 쿼리는 전부 Prepared Statement (mysqli)
6. 스키마(sql/dbcreate.sql) 변경은 1번(김수연)에게 요청
7. **11/30 최종 제출 이후 main 수정 금지** (제출 코드 = 데모 코드)

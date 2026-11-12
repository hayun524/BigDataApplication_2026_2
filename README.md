# 빅데이터응용 기말 프로젝트 — 날씨와 따릉이 이용량

> 작성: 김수연 (초안·작업 규칙·담당표) · 수정: 팀 전체

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
| 번호 | 역할 | 담당 | 파일 | 브랜치 |
|---|---|---|---|---|
| 1 | DB 설계 | 김수연 | sql/dbcreate.sql, sql/dbdrop.sql, sql/dbdump.sql, docs/ERD, station_list.php | `feat/db-suyeon` |
| 2 | 데이터 | 정서진 | sql/dbinsert.sql, data/ 변환 스크립트, weather_manage.php | `feat/data-seojin` |
| 3 | CRUD 웹 | 정하윤 | station_form.php, report.php | `feat/crud-hayun` |
| 4 | 분석 A | 유지민 | aggregate.php, rollup.php | `feat/analysis-jimin` |
| 5 | 분석 B | 노경윤 | ranking.php, window.php | `feat/analysis-kyungyun` |
| 6 | 공통·문서 | 왕은서 | config.php, header.php, login.php, logout.php, index.php | `feat/common-eunseo` |

## 시작하기

### 0. 준비
- 정하윤의 **collaborator 초대 메일 수락** (수락 안 하면 push가 403 오류로 막힘)
- push할 때 비밀번호 칸에는 GitHub 비밀번호가 아니라 **Personal Access Token** 입력
  (GitHub → Settings → Developer settings → Personal access tokens)

### 1. 처음 한 번만: 내려받기 + 내 브랜치 만들기
```bash
# 맥
cd /Applications/XAMPP/xamppfiles/htdocs
# 윈도우
cd C:\xampp\htdocs

git clone https://github.com/hayun524/BigDataApplication_2026_2.git teamXX
cd teamXX
git checkout -b feat/역할-이름          # 위 담당표의 내 브랜치 이름
git push -u origin feat/역할-이름
```
→ XAMPP 켜고 브라우저에서 `localhost/teamXX`로 접속

### 2. 매일 작업할 때
```bash
git pull origin main                    # 시작 전 최신화 (꼭!)
# ... 작업 ...
git add 내파일.php
git commit -m "feat: 무엇을 했는지"
git push
```

### 3. 기능 하나 완성되면 main에 합치기
1. GitHub 레포 화면 → 노란 박스 **Compare & pull request**
2. **Create pull request** → **Merge pull request**
3. 단톡에 "○○ 페이지 merge했어요" 한 줄 남기기

### 4. 이럴 땐 이렇게
| 상황 | 해결 |
|---|---|
| push하면 403 오류 | 초대 수락 안 했거나 토큰 문제 |
| pull할 때 conflict 오류 | 혼자 해결하지 말고 단톡에 스크린샷 |
| 테이블에 컬럼이 더 필요함 | 김수연(1번)에게 요청 |
| "스키마 바뀜" 공지가 옴 | `git pull origin main` → phpMyAdmin에서 dbdrop → dbcreate → dbinsert 다시 실행 |

## 작업 규칙
1. **main에 직접 push 금지.** 각자 브랜치에서 작업 → Pull Request → main에 merge
   - 브랜치 이름: `feat/역할-이름` (위 담당표 참고)
2. 작업 시작 전 `git pull origin main`으로 최신화
3. **자기 파일만 수정.** 남의 파일 고칠 일이 있으면 단톡에 먼저 말하기
4. 모든 파일 상단과 주요 함수에 `// 작성자: 이름` 주석 (SQL은 `-- 작성자: 이름`) ← **지침 필수**
5. DB 연결은 `config.php`만 사용, 사용자 입력 쿼리는 전부 Prepared Statement (mysqli) ← **지침 필수**
   - 컬럼 이름·ORDER BY 기준은 `?`로 바인딩이 안 되므로 허용 목록(화이트리스트)에서만 선택
6. 스키마(sql/dbcreate.sql) 변경은 1번(김수연)에게 요청. **11/12 이후 원칙적으로 변경 금지**
7. **11/30 최종 제출 이후 main 수정 금지** (제출 코드 = 데모 코드, 다르면 감점) ← **지침 필수**

## 일정
| 날짜 | 마감 |
|---|---|
| 11/12 | 스키마 확정 (dbcreate.sql, dbdrop.sql) |
| 11/17 | 중간 점검: 각자 페이지 기본 동작 시연 |
| 11/25 | 개발 마감 |
| 11/30 | 최종 제출: 코드, SQL 4종, 보고서, 영어 논문 |
| 12/1 | 영어 PPT + 데모 영상 |
| 12/3 · 12/7 | 영어 발표·데모 |
| 12/10 | 영어 논문 수정본 |

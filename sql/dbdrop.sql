-- =====================================================================
-- dbdrop.sql : 전체 테이블 삭제 스크립트
-- 작성자     : 김수연 (1. DB 설계)
-- 삭제 순서  : 자식 → 부모 (dbcreate.sql 생성 순서의 역순)
--   FK 체크를 끄지 않고 순서로 해결 → 순서가 틀리면 에러로 바로 알 수 있음
-- =====================================================================

DROP TABLE IF EXISTS fault_report;
DROP TABLE IF EXISTS user_account;
DROP TABLE IF EXISTS rental_daily;
DROP TABLE IF EXISTS weather;
DROP TABLE IF EXISTS calendar;
DROP TABLE IF EXISTS station;
DROP TABLE IF EXISTS district;

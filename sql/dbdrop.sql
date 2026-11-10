-- 작성자: 김수연 (1. DB 설계)
-- dbdrop.sql : 전체 테이블 삭제 (자식 테이블 → 부모 테이블 순서)
USE teamXX;

DROP TABLE IF EXISTS fault_report;
DROP TABLE IF EXISTS rental_daily;
DROP TABLE IF EXISTS weather;
DROP TABLE IF EXISTS user_account;
DROP TABLE IF EXISTS calendar;
DROP TABLE IF EXISTS station;
DROP TABLE IF EXISTS district;

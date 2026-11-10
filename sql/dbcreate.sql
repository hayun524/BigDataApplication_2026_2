-- 작성자: 김수연 (1. DB 설계)
-- dbcreate.sql : 데이터베이스와 테이블 생성 (PK, FK, INDEX 포함)
-- 생성 순서: 부모 테이블 → 자식 테이블

CREATE DATABASE IF NOT EXISTS teamXX DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE teamXX;

-- TODO 1. district      자치구 (생활권, 주간인구지수, 업무/주거 유형)
-- TODO 2. station       대여소 (자치구 FK, 거치대 수, 상태, 한강공원)
-- TODO 3. calendar      날짜 (연·월·계절·요일·공휴일)
-- TODO 4. weather       일별 날씨 (calendar FK)
-- TODO 5. rental_daily  대여소별 일별 이용 (station FK, calendar FK)
-- TODO 6. user_account  로그인 계정
-- TODO 7. fault_report  고장 신고 (station FK, user_account FK)

<?php
// 작성자: 김수연 (1. DB 설계)
// station_list.php : 대여소 검색 (SELECT)
// 입력: 자치구 select, 상태 checkbox, 이름 text → Prepared Statement 동적 쿼리

require_once 'config.php';   // 6번(왕은서) 공통 DB 설정
include 'header.php';        // 6번(왕은서) 공통 메뉴

// TODO: 자치구 목록 불러오기 (district)
// TODO: 검색 조건 받아서 WHERE 절 동적 구성 + bind_param
// TODO: 결과 표 출력 (번호 | 대여소명 | 자치구 | 거치대 | 상태)
?>

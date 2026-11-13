-- 작성자: 왕은서
-- 로그인 테스트 계정 (user_account). 비밀번호는 PHP password_hash()로 만든 bcrypt 해시
-- admin / admin0606 (관리자), user1 / user0606 (일반 사용자)
INSERT INTO user_account (user_id, login_id, password_hash, user_name, role) VALUES
  (1, 'admin', '$2y$10$MdeiqLQn4C54x2EXJKM/j.7Fr3Fstig7Lw7YYb1Afka3KzGo4/pXS', '관리자', 'admin'),
  (2, 'user1', '$2y$10$OkVc1mkvfitJIv0juXqY6eCEKthrSzaCk7W1ryTJFaMknHEVaXc4m', '테스트사용자', 'user');

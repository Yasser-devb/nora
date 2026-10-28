CREATE USER 'nora_test_user'@'%' IDENTIFIED BY 'nora_ci_test_password';
GRANT SELECT, INSERT, DELETE, CREATE, DROP ON nora_test.* TO 'nora_test_user'@'%';

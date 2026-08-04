-- Runs once, when the data volume is first created.
--
-- phpunit.xml points the test suite at its own database (see the DB_DATABASE
-- override there), and the Postgres image only creates the one named in
-- POSTGRES_DB. Without this, `php artisan test` inside the container fails on
-- a database that does not exist — which reads like a broken container rather
-- than a missing CREATE.
CREATE DATABASE pcom_testing;

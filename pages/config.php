<?php
// Basic configuration for MDRRMO web app

date_default_timezone_set('Asia/Manila');

// TODO: Change these to your actual database credentials.
const DB_HOST = 'sql306.infinityfree.com';
const DB_NAME = 'if0_41493614_db_centrix';
const DB_USER = 'if0_41493614';
const DB_PASS = 'VjnIl9huO5vA72I';

// Mail settings for PHPMailer (configure according to your SMTP provider)
const MAIL_FROM_ADDRESS  = 'marteflores07@gmail.com';
const MAIL_FROM_NAME     = 'MDRRMO San Ildefonso';
const MAIL_SMTP_HOST     = 'smtp.gmail.com';
const MAIL_SMTP_PORT     = 587;
const MAIL_SMTP_USERNAME = 'marteflores07@gmail.com';
const MAIL_SMTP_PASSWORD = 'sdayahycscuwagro';
const MAIL_SMTP_SECURE   = 'tls';

// Weather API (optional, used by cron_fetch_weather.php)
// Obtain an API key from a provider such as OpenWeatherMap and place it here.
const WEATHER_API_KEY = '69eaf017b4b5ae9da5f860057b79920d';


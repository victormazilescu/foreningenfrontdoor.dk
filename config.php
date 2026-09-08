<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'dzppntag_evenimente_dk');
define('DB_USER', 'dzppntag_eventmaster');
define('DB_PASS', 'asociatiaFrontDoor2026!');

define('SMTP_HOST',      'mail.foreningenfrontdoor.dk');
define('SMTP_PORT',      465);
define('SMTP_SECURE',    'ssl');
define('SMTP_USER',      'office@foreningenfrontdoor.dk');
define('SMTP_PASS',      'prolaemailofficefrontDoor2027!');
define('SMTP_FROM',      'office@foreningenfrontdoor.dk');
define('SMTP_FROM_NAME', 'Foreningen Front Door');

// Google Sheets — regnskab. Service account credentials (from the JSON key
// downloaded in Google Cloud Console: IAM & admin > Service accounts >
// Keys > Add key > JSON). Never commit this file.
define('GOOGLE_SA_CLIENT_EMAIL', 'regnskab-sheets@front-door-regnskab.iam.gserviceaccount.com');
define('GOOGLE_SA_PRIVATE_KEY', "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCw5WiffctrPGoj\nM1UqV4u8DrOgh+gli932/7Z1Pxs/jSjrGHy9M2mLJZ2HWROKWBSOr/w9+y5p2te4\n6SCJGcHjBFBlKwl9HcbVhngBJSUreQnKvpU/mO8sCMyxEsEp5bPZxwQ07oJACwFA\ny6K5y9PfsO3BTzBRwsO+MSv5Dz2zdnNAqL13/fY04L/d1dttFKtC3uY+nC8RWDOQ\nToG0Od/G/rIotYRiv/wpDxvLgXn6LaiWdpMK7n9ZvmqNqf6H7wlN6ykrPVdE4YAN\nNxNaax5rJPpLCQIPEPtQH3OgOnpKkMFyqXPHHVdj7zDVUIXmRpLY0JjxdXPIv0TT\nTDOOJUQbAgMBAAECggEACONuD12FxBykBKLGq7r8ZXWjpvRNQN23SHru0xNZribM\noIt5cRRNQFWihbN/HNtyJP1IwmCFo4IAhuUH4nut11dXJs4zytqdLAt2qjAQnw+U\n42ASJcDse5zxlBAqLo3BLLcoSfSWDNvAs13IDmfkfH/qYmU1O1Z6+WqFGB2g7wx2\nHnHtZ90trzZSPhN2qzIB5QWj5vtBEYUxppzgMG9PBpZQNViT6lRAZitTFOwobf3c\n2kJJldkx9KRLTcQ9HmJN0sLZ7M/bL748OerfL2+n5Y7eE1evJJs/IJn0gYpLIouQ\naqC+qb5WGFpyYV4lWC87m1tcDYf1kNeat3EwFoZzIQKBgQDq+eKP0LjwzU99O0kW\n6r784tg2m4dKdakLIJFpEPJREwVW877OM/dZfA7PIB7vNCJqe8tUAT+gOatykp1l\n8yDhWjkxFiZ5VZAFITbypyGEdj58h34jstvVrZvYr1W9FcjJOMwhrqe45swdMOyJ\n7GwG1n+I3r27Vs4LbB6UOvx86wKBgQDAuTRtGyKx4Xbe8MejG25lS+1tHtUkS5dH\nPJ9CXEN6p25zGYmy7MTY5/M4NFhojlvfLGIG0h2W6rl+T9J0dhCbs+U5fQusIlxx\nqKth5pvTPCEO95HT17UNFKGG7FKIvmfOspVPahZSU7yinyQalINCp1zY9qb/q8Si\nqQgwGarJkQKBgQC7AdEcYCMwElZW9p3+zSjfHrKxEyqjSe0VXAAePEx91cOEJk0O\n0zDiWOd4VLoJ6dYSJR/3ZV7756nZb3IxN0RN1X564IQSQNR0ILEYgYcdYvXsKfFr\n++cVsiu8Uh7Mc8/uxXNAwz3c3GJKQSufwTdgYcnyZkNeG4G0eYIEusVDrQKBgDru\nZyGV0p4iG39AkUtG8BL5jLh5XSOkGbYmy2w3Wkr/N77qaDjWPbs18iGVoBMYtO8h\nWzhKt9GWJPKC5g/Gqn1yHP3fRtp0B2CZ+w4MvklxcYpqGaV1qF8/l8TyLqqxznxe\nD1ohToIOKPhxQVD/aMPQ+Ys+oQI9O/uhRGew8ZCBAoGAb7pziMg2fEqLjl8BtEUY\nIUq3wsiUHVRcbTEgO33fUIZGhsCJJLo4YgIVVF4vmO3k0RMvQNhk8omxskva16Ew\nj7waueQFu+U0MJgHKSYXdVt/D7GIpsw/cYxWOiCOdh1XbJOqaz38CdVOFnd6syME\nrnGCN3hwspJpUsZxbsngr+g=\n-----END PRIVATE KEY-----\n");
define('GOOGLE_SHEETS_SPREADSHEET_ID', '18kTSs4_8ptVAh9PX6nYEQMpMWaYofLUF6gOsjcrOr8E');

// Facebook Page + Instagram Business (event-social.php)
define('FB_PAGE_ID', '1272904559239977');
define('FB_PAGE_ACCESS_TOKEN', 'EAAh8ZBCuTNesBSY2hwYZBtmc4g4T4xpQECGKD2X9rkXNr8ReJF0tNOQjjRmVjRi3lMtqLlWDT0HOb9sGAezMNwCL7TWSstE9QA37y6vnALPJkrqwKTzLyiHdIlmUTZC9G8N839lmZBNJzPisywXZBklkZCF0SxFHS6fNOxrBoaoxlzQj6R1Ll1Gh8SIgugjBjJpgZDZD');
define('IG_BUSINESS_ACCOUNT_ID', '17841435038628100');
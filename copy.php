INSERT INTO settings (setting_key, setting_value) VALUES
('telegram_bot_token', '8943872571:AAGGcY6fePLNH3U76yqZE3DGrHDPkkNRRLc'),
('gemini_api_key', 'AIzaSyB61T15UYTQiQ_2qLx_W49z9aJk4-v-2kE'),
('gemini_model', 'gemini-1.5-flash')
ON DUPLICATE KEY UPDATE 
setting_value = VALUES(setting_value);


https://api.telegram.org/bot8943872571:AAGGcY6fePLNH3U76yqZE3DGrHDPkkNRRLc/setWebhook?url=https://tradewithai.xyz/bot/telegram_webhook.php


INSERT INTO settings (setting_key, setting_value) VALUES
('openai_api_key', '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'),
('openai_model', 'gpt-4.1-mini')
ON DUPLICATE KEY UPDATE 
setting_value = VALUES(setting_value);
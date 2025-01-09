FROM php:8.2-fpm

# 安裝系統依賴
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# 清理 apt 快取
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 安裝 PHP 擴展
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 設定工作目錄
WORKDIR /var/www

# 複製專案文件
COPY . /var/www

# 設定目錄權限
RUN chown -R www-data:www-data /var/www

# 安裝專案依賴
RUN composer install --no-interaction --no-dev --optimize-autoloader

# 設定環境變數
ENV PHP_MEMORY_LIMIT=512M

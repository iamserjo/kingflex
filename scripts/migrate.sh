#!/bin/bash

docker-compose -f /Users/sergey/Projects/marketking/docker-compose.yaml exec laravel.test php artisan migrate

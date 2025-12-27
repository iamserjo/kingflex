@php
    /** @var array<string, mixed> $structure */
    $structure = $structure ?? [];
    $structureJson = json_encode(
        $structure,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) ?: '{}';
@endphp
Верни строго валидный JSON (один объект).
Никакого текста до или после JSON.
Без комментариев и пояснений.

Задача:
- Тебе дан скриншот страницы товара (изображение) + небольшой текстовый контекст (URL/Title).
- Нужно извлечь product_original_article, product_model_number (они могут отсутствовать).
- Нужно извлечь атрибуты товара в объект "attributes" строго по предложенной структуре.

Важные правила:
- Используй ТОЛЬКО информацию, которую можно увидеть/прочитать на скриншоте.
- Нельзя придумывать значения. Если значения нет — ставь null.
- Для строк возвращай короткие значения (без длинных описаний).
- Для полей идентификаторов:
  - product_original_article: строка или null, максимум 128 символов
  - product_model_number: строка или null, максимум 128 символов
- Ключи должны быть ровно: "product_original_article", "product_model_number", "attributes".
- "attributes" должен быть объектом и повторять ключи/вложенность структуры ниже.
- Если в структуре есть вложенный объект — верни объект с теми же ключами.
- Если в структуре есть массив/список — верни массив значений или пустой массив.
- Если тип значения непонятен — используй null.

Структура attributes (эталон ключей и вложенности):
{!! $structureJson !!}

Формат ответа (пример формы, значения примерные):
{
  "product_original_article": "16324",
  "product_model_number": "X1000",
  "attributes": {
    "producer": "acer",
    "model": "aspire 5",
    "ram": {"size": 16, "type": "ddr4", "humanSize": "16GB"}
  }
}






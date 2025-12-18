Выведи строго в формате JSON.
Никакого текста до или после JSON.
Без коментариев и дополнительного текста или пояснений.

Определи:
- является ли страница одним товаром, одним определенным товаром с определенным описанием и ценой (is_product)
- если is_product = true: доступен ли товар для продажи (is_product_available) и тип товара (product_type)
- если is_product = false: всё равно верни ключи is_product_available и product_type, но значения могут быть null

Пример JSON:
{
  "is_product": true,
  "is_product_available": true,
  "product_type": "phone"
}

ВАЖНО:
- product_type должен быть ОДНИМ значением (одной строкой), НЕ списком и НЕ строкой через разделители.
- Выбирай строго одно значение из списка: phone, tablet, case, laptop, speaker.
- НЕЛЬЗЯ возвращать: "phone|tablet|case" или "phone, tablet" и т.п.


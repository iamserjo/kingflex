@php
    /** @var array<string, mixed> $structure */
    $structure = $structure ?? [];
    $structureJson = json_encode(
        $structure,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) ?: '{}';
@endphp
Return strictly valid JSON (single object).
No text before or after JSON.
No comments or explanations.

Task:
- You are given purified HTML content of a product page (text representation) + metadata (URL/Title/Meta description).
- Extract product_code, product_model_number (they may be absent).
- Determine product condition (new or used) in the "used" field.
- Extract product attributes into "attributes" object strictly following the provided structure.

Important rules:
- Use ONLY information found in the provided HTML content.
- Do NOT invent values. If value is not found — use null.
- For strings return short values (no long descriptions).
- Identifier fields:
  - sku: ALWAYS null (do not fill this field)
  - product_code: store/website internal product code (e.g.: "85605", "78862_5"), string or null
  - product_model_number: manufacturer model number (e.g.: "MG8H4AF/A", "MNKX3/MNKW3"), string or null
- Product condition field:
  - used: boolean (true = used/refurbished, false = new) or null if cannot determine
- Required keys: "sku", "product_code", "product_model_number", "used", "attributes".
- "attributes" must be an object matching the keys/nesting of the structure below.
- If structure has nested object — return object with same keys.
- If structure has array/list — return array of values or empty array.
- If value type is unclear — use null.

Numeric values with units (IMPORTANT for search):
- For ANY numeric attribute (RAM, storage, screen size, weight, battery, etc.) ALWAYS provide BOTH:
  - "size" or numeric field: raw number as int or float (e.g.: 16, 6.7, 512, 5000)
  - "humanSize" or human-readable field: formatted string with unit (e.g.: "16GB", "6.7 inch", "512GB", "5000mAh")
- This applies to: memory, storage, display size, battery capacity, weight, dimensions, etc.
- Examples:
  - RAM: {"size": 8, "humanSize": "8GB", "type": "ddr5"}
  - Storage: {"size": 256, "humanSize": "256GB", "type": "ssd"}
  - Screen: {"size": 6.7, "humanSize": "6.7 inch", "resolution": "2796x1290"}
  - Battery: {"capacity": 4500, "humanCapacity": "4500mAh"}
  - Weight: {"weight": 240, "humanWeight": "240g"}

How to find product_code and product_model_number:
- product_code — look for labels: "Артикул", "Код товара", "Код:", "Product code", "№", "Item #", "ID товара", "SKU" (on website)
  This is store's internal identifier, usually numeric (85605) or with separator (78862_5)
- product_model_number — look for labels: "Модель", "Model", "Part number", "P/N", "Номер модели", "MPN"
  This is manufacturer's identifier, often contains letters and slashes (MG8H4AF/A, MNKX3/MNKW3)
- If cannot find value — use null

How to determine product condition (used):
- used = true, if you see: "б/у", "б.у.", "бу", "бывший в употреблении", "подержанный", "used", "refurbished", "восстановленный", "second hand", "pre-owned", "like new" (but was used)
- used = false, if explicitly stated: "новый", "new", "в упаковке", "запечатан", "sealed", "brand new"
- used = null, if condition is not explicitly stated

Attributes structure (reference for keys and nesting):
{!! $structureJson !!}

Response format (example, values are illustrative):
{
  "sku": null,
  "product_code": "85605",
  "product_model_number": "MG8H4AF/A",
  "used": false,
  "attributes": {
    "producer": "apple",
    "model": "iphone 14 pro",
    "ram": {"size": 6, "humanSize": "6GB", "type": "lpddr5"},
    "storage": {"size": 256, "humanSize": "256GB", "type": "nvme"},
    "screen": {"size": 6.1, "humanSize": "6.1 inch", "resolution": "2556x1179"},
    "battery": {"capacity": 3200, "humanCapacity": "3200mAh"}
  }
}

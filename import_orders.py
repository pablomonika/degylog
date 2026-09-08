#!/usr/bin/env python3
import json, re, sys

# Read orders JSON from stdin
raw = sys.stdin.read()

# Fix markdown formatting
raw = re.sub(r'\[([^\]]+)\]\([^)]+\)', r'\1', raw)

orders = json.loads(raw)
print(f"Read {len(orders)} orders")

# Read current crm_data.json
with open('public_html/crm_data.json', 'r', encoding='utf-8') as f:
    data = json.load(f)

# Update orders
data['afrizon_orders_v5'] = {
    "t": 1788826607752,
    "d": orders
}

# Save
with open('public_html/crm_data.json', 'w', encoding='utf-8') as f:
    json.dump(data, f, ensure_ascii=False)

print(f"✅ Saved {len(orders)} orders to crm_data.json")

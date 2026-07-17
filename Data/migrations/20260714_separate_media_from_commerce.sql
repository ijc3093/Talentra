-- Media publishers (CNN, news, sports, etc.) are not commerce sellers.
-- Clear accidental commerce brand links and stop treating them as shops.

UPDATE organizations
SET commerce_brand_id = NULL,
    org_kind = IF(org_kind = 'shop', 'community', org_kind),
    updated_at = NOW()
WHERE is_publisher_org = 1
  AND publisher_category IS NOT NULL
  AND TRIM(publisher_category) <> ''
  AND LOWER(TRIM(publisher_category)) <> 'commerce';

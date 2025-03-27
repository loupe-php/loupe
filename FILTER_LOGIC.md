WITH
-- Branch A: multi-attribute filters
filtered_ratings AS (s
SELECT d.id AS document_id, d.document, ma.attribute, ma.numeric_value
FROM documents d
JOIN multi_attributes_documents mad ON mad.document = d.id
JOIN multi_attributes ma ON ma.id = mad.attribute
WHERE ma.attribute = 'ratings' AND ma.numeric_value BETWEEN 2 AND 6
),

filtered_dates AS (
SELECT d.id AS document_id, d.document, ma.attribute, ma.numeric_value
FROM documents d
JOIN multi_attributes_documents mad ON mad.document = d.id
JOIN multi_attributes ma ON ma.id = mad.attribute
WHERE ma.attribute = 'dates' AND ma.numeric_value BETWEEN 2 AND 6
),

branch_a_docs AS (
SELECT document_id, document FROM filtered_ratings
INTERSECT
SELECT document_id, document FROM filtered_dates
),

-- Branch B: simple document filter
branch_b_docs AS (
SELECT id AS document_id, document
FROM documents
WHERE age > 42
),

-- Union both branches into the matched set
matched_documents AS (
SELECT * FROM branch_a_docs
UNION
SELECT * FROM branch_b_docs
),

-- Only pull attribute rows from docs that matched branch A (since branch B has no metadata)
filtered_metadata AS (
SELECT * FROM filtered_ratings
UNION ALL
SELECT * FROM filtered_dates
),

final_metadata AS (
SELECT fm.*
FROM filtered_metadata fm
JOIN branch_a_docs b ON b.document_id = fm.document_id
),

-- Aggregation (only from branch A)
min_ratings AS (
SELECT document_id, MIN(numeric_value) AS min_rating
FROM final_metadata
WHERE attribute = 'ratings'
GROUP BY document_id
)

-- Final output
SELECT
m.document,
r.min_rating,
COUNT(*) OVER () AS totalHits
FROM matched_documents m
LEFT JOIN min_ratings r ON r.document_id = m.document_id
ORDER BY r.min_rating
LIMIT 20;
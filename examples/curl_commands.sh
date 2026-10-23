#!/usr/bin/env bash

BASE_URL="http://127.0.0.1:8000/api"

echo "=== 1. Create Policy Request (Intake) ==="
curl -s -X POST "${BASE_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | jq .

echo -e "\n=== 2. Duplicate Intake (Safely returns existing 200 OK) ==="
curl -s -X POST "${BASE_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | jq .

echo -e "\n=== 3. Get Policy Request Details (with Audit Trail) ==="
curl -s -X GET "${BASE_URL}/policy-requests/1" | jq .

echo -e "\n=== 4. Submit Issuance (SUCCESS scenario) ==="
curl -s -X POST "${BASE_URL}/policy-requests/1/submit" \
  -H "Content-Type: application/json" \
  -d '{"scenario": "SUCCESS"}' | jq .

echo -e "\n=== 5. Duplicate Callback Delivery (Idempotent) ==="
curl -s -X POST "${BASE_URL}/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": "EVT-MOCK-999",
    "event_type": "POLICY_ISSUED",
    "policy_request_uuid": "...",
    "policy_number": "POL-CALLBACK-1234"
  }' | jq .

echo -e "\n=== 6. List Requests with Status Filter & Pagination ==="
curl -s -X GET "${BASE_URL}/policy-requests?status=ISSUED&per_page=10" | jq .

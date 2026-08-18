#!/usr/bin/env python3
"""Generate webhook signature fixtures with Didit's exact server-side algorithm.

The three helpers below are copied verbatim from service-didit-verification
(src/sessions/utils/webhooks.py) so the fixtures are byte-identical to what a real
delivery carries. The PHP test then has to reproduce the canonical form from the
transmitted body alone -- which is the whole point of X-Signature-V2.

Usage:  python3 tests/generate-fixtures.py > tests/fixtures/webhook-signatures.json
"""

import hashlib
import hmac
import json
import sys

SECRET = "wh_test_secret_9f2c4b8e1a7d"


def shorten_floats(data):
    if isinstance(data, dict):
        return {key: shorten_floats(value) for key, value in data.items()}
    if isinstance(data, list):
        return [shorten_floats(item) for item in data]
    if isinstance(data, float) and data.is_integer():
        return int(data)
    return data


def generate_signature(secret_shared_key, data):
    encoded_data = json.dumps(shorten_floats(data), sort_keys=True, separators=(",", ":"))
    signature = hmac.new(
        secret_shared_key.encode("utf-8"), encoded_data.encode("utf-8"), hashlib.sha256
    ).hexdigest()
    return encoded_data, signature


def generate_signature_v2(secret_shared_key, data):
    encoded_data = json.dumps(
        shorten_floats(data), sort_keys=True, separators=(",", ":"), ensure_ascii=False
    )
    return hmac.new(
        secret_shared_key.encode("utf-8"), encoded_data.encode("utf-8"), hashlib.sha256
    ).hexdigest()


def generate_signature_simple(secret_shared_key, data):
    canonical_string = ":".join(
        [
            str(data.get("timestamp", "")),
            str(data.get("session_id", "")),
            str(data.get("status", "")),
            str(data.get("webhook_type", "")),
        ]
    )
    return hmac.new(
        secret_shared_key.encode("utf-8"), canonical_string.encode("utf-8"), hashlib.sha256
    ).hexdigest()


TIMESTAMP = 1774970000
SESSION_ID = "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"


def envelope(**extra):
    payload = {
        "event_id": "9c0c8b8a-1111-4222-9333-444444444444",
        "webhook_type": "status.updated",
        "timestamp": TIMESTAMP,
        "created_at": TIMESTAMP - 6,
        "application_id": "11111111-2222-3333-4444-555555555555",
        "environment": "live",
        "session_id": SESSION_ID,
        "status": "Approved",
        "workflow_id": "22222222-3333-4444-5555-666666666666",
        "workflow_version": 4,
        "vendor_data": "wp-42",
    }
    payload.update(extra)
    return payload


CASES = {
    # The ordinary delivery a WordPress site receives.
    "basic_approved": envelope(
        metadata={"wp_user_id": 42},
        decision={
            "session_id": SESSION_ID,
            "status": "Approved",
            "id_verifications": [{"node_id": "id_verification_1", "status": "Approved", "warnings": []}],
            "reviews": [],
        },
    ),
    # ensure_ascii=False is the difference between X-Signature and X-Signature-V2:
    # the raw body carries é escapes, the canonical form carries the characters.
    "unicode_names": envelope(
        decision={
            "session_id": SESSION_ID,
            "status": "Approved",
            "id_verifications": [
                {
                    "node_id": "id_verification_1",
                    "first_name": "José",
                    "last_name": "Müller-Ünlü",
                    "place_of_birth": "北京",
                    "status": "Approved",
                }
            ],
        },
    ),
    # Empty maps are routine in Didit payloads and are the trap an associative
    # json_decode falls into: {} round-trips to [] and the digest never matches.
    "empty_objects": envelope(
        metadata={},
        decision={
            "session_id": SESSION_ID,
            "status": "Approved",
            "risk_view": {
                "countries": {"risk_scores": {}},
                "crimes": {"risk_scores": {}},
                "custom_list": {},
            },
            "reviews": [],
        },
    ),
    # PHP escapes forward slashes by default; Python never does.
    "slashes_in_urls": envelope(
        decision={
            "session_id": SESSION_ID,
            "status": "Approved",
            "id_verifications": [
                {
                    "node_id": "id_verification_1",
                    "front_image": "https://media.didit.me/sessions/abc/front.jpg?sig=a/b+c",
                }
            ],
        },
    ),
    # Whole-valued floats are serialised as ints by shorten_floats; fractional ones
    # keep Python's shortest round-trip repr, which PHP matches at serialize_precision=-1.
    "float_scores": envelope(
        decision={
            "session_id": SESSION_ID,
            "status": "Approved",
            "liveness_checks": [{"node_id": "liveness_1", "score": 95.4, "threshold": 100.0}],
            "face_matches": [{"node_id": "face_match_1", "score": 96.15, "threshold": 0.0}],
        },
    ),
    # Python sorts keys by code point; PHP's default ksort would put "9" before "10".
    "numeric_like_keys": envelope(
        metadata={"9": "nine", "10": "ten", "2": "two", "wp_user_id": 42},
    ),
    # Arrays keep their order while object keys are sorted, and null/bool must survive.
    "mixed_types": envelope(
        decision={
            "session_id": SESSION_ID,
            "status": "Approved",
            "reviews": [],
            "warnings": [{"risk": "b"}, {"risk": "a"}],
            "expiration_date": None,
            "is_sandbox": False,
            "nfc_available": True,
        },
    ),
    # A Declined delivery, to prove status is what the envelope signature binds.
    "declined": envelope(status="Declined", metadata={"wp_user_id": 42}),
}


def main():
    out = {"secret": SECRET, "timestamp": TIMESTAMP, "session_id": SESSION_ID, "cases": {}}
    for name, payload in CASES.items():
        body, signature = generate_signature(SECRET, payload)
        out["cases"][name] = {
            "body": body,
            "signatures": {
                "raw": signature,
                "v2": generate_signature_v2(SECRET, payload),
                "simple": generate_signature_simple(SECRET, payload),
            },
        }
    json.dump(out, sys.stdout, indent=2, ensure_ascii=True)
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()

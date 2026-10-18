#!/usr/bin/env python3
"""Conformance check of a Commerce MCP server against spec/CONTRACT.md.

    python3 conformance/check.py https://store.example/wp-json/webrobot-mcp/v1/mcp <token>
    python3 conformance/check.py <endpoint> <token> --write <sku>   # also update + revert one price

Read-only by default. With --write it raises the price of <sku> by 1%, checks the audit log and
reverts it. Standard library only.
"""
import argparse
import json
import sys
import urllib.error
import urllib.request

PRODUCT_KEYS = {"sku", "title", "brand", "mpn", "ean", "category", "price", "regularPrice", "currency", "inStock", "url"}
TOOLS = {"list_products", "get_product", "update_price", "revert_price", "list_price_changes"}

failures = 0


def check(cond, what):
    global failures
    print(("  ok   " if cond else "  FAIL ") + what)
    if not cond:
        failures += 1
    return cond


def post(url, token, payload):
    req = urllib.request.Request(url, data=json.dumps(payload).encode(), method="POST", headers={
        "Content-Type": "application/json",
        "Accept": "application/json, text/event-stream",
        **({"Authorization": f"Bearer {token}"} if token else {}),
    })
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            body = r.read().decode()
            return r.status, (json.loads(body) if body.strip() else None)
    except urllib.error.HTTPError as e:
        return e.code, None


def call(url, token, name, args, rid=[100]):
    rid[0] += 1
    status, body = post(url, token, {"jsonrpc": "2.0", "id": rid[0], "method": "tools/call",
                                     "params": {"name": name, "arguments": args}})
    if status != 200 or not body or "result" not in body:
        return None, f"HTTP {status} {body}"
    res = body["result"]
    return res, None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("endpoint")
    ap.add_argument("token")
    ap.add_argument("--write", metavar="SKU", help="also test update_price + revert_price on this SKU")
    a = ap.parse_args()

    print("transport")
    status, _ = post(a.endpoint, "", {"jsonrpc": "2.0", "id": 1, "method": "ping"})
    check(status == 401, f"no token → 401 (got {status})")
    status, body = post(a.endpoint, a.token, {"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {
        "protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "conformance", "version": "1"}}})
    check(status == 200 and body and body.get("result", {}).get("protocolVersion"), "initialize")
    status, _ = post(a.endpoint, a.token, {"jsonrpc": "2.0", "method": "notifications/initialized"})
    check(status == 202, f"notifications/initialized → 202 (got {status})")
    status, body = post(a.endpoint, a.token, {"jsonrpc": "2.0", "id": 2, "method": "tools/list"})
    names = {t["name"] for t in (body or {}).get("result", {}).get("tools", [])}
    check(TOOLS <= names, f"tools/list has the 5 tools (missing: {sorted(TOOLS - names)})")

    print("read")
    res, err = call(a.endpoint, a.token, "list_products", {"limit": 5})
    sc = (res or {}).get("structuredContent") or {}
    items = sc.get("items") or []
    check(not err and isinstance(sc.get("total"), int) and items, f"list_products returns items {err or ''}")
    if items:
        p = items[0]
        check(PRODUCT_KEYS <= set(p), f"product has all keys (missing: {sorted(PRODUCT_KEYS - set(p))})")
        check(isinstance(p.get("price"), (int, float)) and p["price"] > 0, "price is a positive number")
        res, err = call(a.endpoint, a.token, "get_product", {"sku": p["sku"]})
        check(not err and (res or {}).get("structuredContent", {}).get("sku") == p["sku"], "get_product by sku")
    res, err = call(a.endpoint, a.token, "get_product", {"sku": "__no_such_sku__"})
    check(res is not None and res.get("isError") is True, "unknown sku → isError")

    if a.write:
        print("write")
        res, err = call(a.endpoint, a.token, "get_product", {"sku": a.write})
        before = (res or {}).get("structuredContent", {}).get("price")
        if check(isinstance(before, (int, float)), f"get_product {a.write}"):
            res, err = call(a.endpoint, a.token, "update_price", {"sku": a.write, "price": round(before * 1.01, 2),
                                                                 "reason": "conformance check"})
            check(res is not None and not res.get("isError"), f"update_price +1% {err or ''}")
            res, err = call(a.endpoint, a.token, "update_price", {"sku": a.write, "price": round(before * 3, 2)})
            check(res is not None and res.get("isError") is True, "update_price ×3 rejected by the store guard")
            res, err = call(a.endpoint, a.token, "list_price_changes", {"sku": a.write, "limit": 1})
            ch = (res or {}).get("structuredContent", {}).get("changes") or []
            check(ch and ch[0].get("reason") == "conformance check", "change is in the audit log")
            res, err = call(a.endpoint, a.token, "revert_price", {"sku": a.write, "reason": "conformance revert"})
            after = (res or {}).get("structuredContent", {}).get("price")
            check(after is not None and abs(after - before) < 0.005, f"revert_price restores {before} (got {after})")

    print("PASS" if failures == 0 else f"{failures} FAILURE(S)")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())

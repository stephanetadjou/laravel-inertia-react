import json
from types import SimpleNamespace

import pytest
from fastapi.testclient import TestClient

from app import main


class FakeStream:
    def __init__(self, chunks, stop="end_turn"):
        self.chunks, self.stop = chunks, stop

    async def __aenter__(self):
        return self

    async def __aexit__(self, *exc):
        return False

    @property
    def text_stream(self):
        async def gen():
            for c in self.chunks:
                yield c
        return gen()

    async def get_final_message(self):
        return SimpleNamespace(stop_reason=self.stop, content=[1] if self.chunks else [])


class FakeClient:
    def __init__(self, stream):
        self.calls = []
        self.beta = SimpleNamespace(messages=SimpleNamespace(stream=self._stream))
        self._s = stream

    def _stream(self, **kwargs):
        self.calls.append(kwargs)
        return self._s


@pytest.fixture(autouse=True)
def setup(monkeypatch, tmp_path):
    kbfile = tmp_path / "kb.json"
    kbfile.write_text(json.dumps({"knowledge": "Solo : 9 900 FCFA par mois."}), encoding="utf-8")
    monkeypatch.setattr(main, "KB_PATH", str(kbfile))
    main.kb.text, main.kb.loaded_at = "", 0
    main.limiter.hits.clear()


def events(body: str):
    out = []
    for chunk in body.strip().split("\n\n"):
        ev, data = "message", None
        for line in chunk.split("\n"):
            if line.startswith("event:"):
                ev = line[6:].strip()
            elif line.startswith("data:"):
                data = json.loads(line[5:])
        out.append((ev, data))
    return out


def test_streams_answer_with_knowledge_in_cached_system(monkeypatch):
    fake = FakeClient(FakeStream(["Solo coûte ", "9 900 FCFA."]))
    monkeypatch.setattr(main, "get_client", lambda: fake)
    res = TestClient(main.app).post("/v1/assistant", json={"messages": [{"role": "user", "content": "Combien ?"}], "page": "/tarifs"})
    assert res.status_code == 200
    evs = events(res.text)
    assert "".join(d["text"] for e, d in evs if e == "message") == "Solo coûte 9 900 FCFA."
    assert evs[-1] == ("done", {"stop": "end_turn"})
    call = fake.calls[0]
    assert call["model"] == "claude-opus-5-5"
    assert call["fallbacks"] == "default"
    assert "9 900 FCFA" in call["system"][0]["text"]
    assert call["system"][0]["cache_control"] == {"type": "ephemeral"}
    assert call["messages"][-1]["content"].endswith("Combien ?")


def test_refusal_adds_safe_message(monkeypatch):
    monkeypatch.setattr(main, "get_client", lambda: FakeClient(FakeStream([], stop="refusal")))
    res = TestClient(main.app).post("/v1/assistant", json={"messages": [{"role": "user", "content": "x"}]})
    texts = [d["text"] for e, d in events(res.text) if e == "message"]
    assert texts == [main.REFUSAL_TEXT]


def test_rejects_bad_conversations():
    c = TestClient(main.app)
    assert c.post("/v1/assistant", json={"messages": []}).status_code == 422
    assert c.post("/v1/assistant", json={"messages": [{"role": "assistant", "content": "x"}]}).status_code == 422
    assert c.post("/v1/assistant", json={"messages": [{"role": "user", "content": "x" * 601}]}).status_code == 422
    assert c.post("/v1/assistant", json={"messages": [{"role": "system", "content": "x"}]}).status_code == 422


def test_merges_turns_and_drops_leading_assistant():
    body = main.AskBody(messages=[{"role": "assistant", "content": "Bonjour"}, {"role": "user", "content": "a"}, {"role": "user", "content": "b"}])
    assert main.conversation(body) == [{"role": "user", "content": "a\nb"}]


def test_rate_limit(monkeypatch):
    monkeypatch.setattr(main, "get_client", lambda: FakeClient(FakeStream(["ok"])))
    monkeypatch.setattr(main, "PER_MINUTE", 2)
    c = TestClient(main.app)
    body = {"messages": [{"role": "user", "content": "x"}]}
    assert [c.post("/v1/assistant", json=body).status_code for _ in range(3)] == [200, 200, 429]


def test_cors_allows_site_only():
    c = TestClient(main.app)
    ok = c.options("/v1/assistant", headers={"Origin": "https://menentia.com", "Access-Control-Request-Method": "POST"})
    bad = c.options("/v1/assistant", headers={"Origin": "https://exemple.com", "Access-Control-Request-Method": "POST"})
    assert ok.headers.get("access-control-allow-origin") == "https://menentia.com"
    assert "access-control-allow-origin" not in bad.headers

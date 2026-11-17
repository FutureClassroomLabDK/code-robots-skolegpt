"""Run one program through edsim (the ed-robot-simulator) for the tutor agent.

Called by includes/class-coderobots-simulator.php inside a bubblewrap jail
(no network, no host filesystem apart from /usr and the edsim checkout).

stdin:  {"cmd": "run", "code": str, "platform": "spike|microbit|csai", "scenario": str}
        {"cmd": "scenarios"}
stdout: one JSON object
        run       -> {"ok": bool, "text": str, "error": {type, message, line} | null}
        scenarios -> {"scenarios": {name: {"platform": str, "description": str}}}
        failure   -> {"harness_error": str}
"""
import json
import os
import sys

MAX_TEXT = 8000


def main():
    sys.path.insert(0, os.environ.get("EDSIM_DIR", "/edsim"))
    try:
        req = json.load(sys.stdin)
        import edsim

        if req.get("cmd") == "scenarios":
            out = {
                name: {"platform": plat or "", "description": desc or ""}
                for name, (plat, desc) in edsim.list_scenarios().items()
            }
            return {"scenarios": out}

        res = edsim.simulate(str(req.get("code", "")), req.get("platform"), req.get("scenario"))
        text = res.text
        if len(text) > MAX_TEXT:
            text = text[: MAX_TEXT - 200] + "\n[... trace shortened ...]\n" + text[-180:]
        err = res.error
        if err:
            err = {k: err.get(k) for k in ("type", "message", "line")}
        return {"ok": bool(res.ok), "text": text, "error": err}
    except Exception as e:  # noqa: BLE001 - report every harness failure as JSON
        return {"harness_error": f"{type(e).__name__}: {e}"}


if __name__ == "__main__":
    out = main()
    sys.stdout = sys.__stdout__
    sys.stdout.write(json.dumps(out, default=str))

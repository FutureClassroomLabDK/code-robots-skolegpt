"""Export the tutor prompt bundles to JSON for the WordPress plugin.

The PHP tutor pipeline (wordpress-plugin/coderobots/includes/class-coderobots-tutor.php)
reads prompts/tutor-prompts.json, generated from the Python sources in prompts/
(the source of truth). Run by scripts/package-wordpress-plugin.mjs:

    python3 -I scripts/export_tutor_prompts.py <out.json>
"""

import json
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import prompts  # noqa: E402  (prompts/ — plain data, no deps)

HW_MODES = ["spike", "microbit", "lego"]

out = {
    "levels": prompts.LEVEL_PROMPTS,
    "hw": {
        hw: {
            "preamble": prompts.PREAMBLES[hw],
            "examples": prompts.EXAMPLES[hw],
            # dicts keep insertion order; the router lists bundles in this order.
            "bundles": prompts.BUNDLES[hw],
            "descriptions": prompts.BUNDLE_DESCRIPTIONS[hw],
            "defaults": prompts.DEFAULT_BUNDLES[hw],
        }
        for hw in HW_MODES
    },
}

pathlib.Path(sys.argv[1]).write_text(json.dumps(out, ensure_ascii=False, indent=1), encoding="utf-8")

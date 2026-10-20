"""Charts for the README, from the frozen standards file (config/standards/standards-v1.json).

Run: analysis/.venv/bin/python analysis/charts.py  ->  docs/images/*.png
"""
import json
from pathlib import Path

import matplotlib.pyplot as plt

ROOT = Path(__file__).resolve().parent.parent
STANDARDS = json.loads((ROOT / "config/standards/standards-v1.json").read_text())
OUT = ROOT / "docs/images"

ROLES = {"CB": "Centre-backs", "FB": "Full-backs", "DM": "Defensive mids", "CM": "Central mids"}
COLORS = {"CB": "#5c6bc0", "FB": "#8c9eff", "DM": "#26a69a", "CM": "#80cbc4"}
STATS = {
    "tackles_won": "Tackles won",
    "interceptions": "Interceptions",
    "clearances": "Clearances",
    "ball_recoveries": "Ball recoveries",
    "accurate_opposition_half_passes": "Passes in the opposition half",
    "xg_buildup": "xGBuildup (involvement in build-up)",
}


def link_with_winning() -> None:
    """Per role and statistic: correlation with team goal difference, average of both seasons."""
    fig, ax = plt.subplots(figsize=(9, 4.6), dpi=150)
    rows = list(STATS)[::-1]
    for i, stat in enumerate(rows):
        for j, (role, label) in enumerate(ROLES.items()):
            s = STANDARDS["roles"][role]["statistics"][stat]
            value = sum(s["relevance"].values()) / len(s["relevance"])
            y = i + (j - 1.5) * 0.15
            # Filled: counts in the role's standard (positive and significant in each season).
            ax.scatter(value, y, s=60, color=COLORS[role], edgecolor=COLORS[role],
                       facecolor=COLORS[role] if s["used"] else "white", linewidth=1.8, zorder=3,
                       label=label if i == 0 else None)
    ax.axvline(0, color="#999", linewidth=1)
    ax.axhspan(len(rows) - 4.5, len(rows) - 0.5, color="#f3f3f3", zorder=0)
    ax.set_yticks(range(len(rows)), [STATS[s] for s in rows])
    ax.set_xlabel("Link with winning: correlation with team goal difference per match\n"
                  "(possession taken into account; average of 2024/25 and 2025/26)")
    fig.suptitle("Defending counts do not go with winning. Passing forward does.", x=0.02, ha="left", fontweight="bold")
    ax.legend(loc="upper right", fontsize=8, frameon=False, title="filled = counts in the role's standard\nopen = no replicated link",
              title_fontsize=8)
    ax.spines[["top", "right"]].set_visible(False)
    fig.tight_layout()
    fig.savefig(OUT / "link-with-winning.png")


if __name__ == "__main__":
    link_with_winning()

import html, os, sys
sys.path.insert(0, os.path.dirname(__file__))
from posts import POSTS, HASH
B = "/mnt/project-files/branding-nentix"
OUT = os.path.join(os.path.dirname(__file__), "html"); os.makedirs(OUT, exist_ok=True)
e = html.escape
CSS = f"""
@font-face{{font-family:Outfit;font-weight:500;src:url(file://{B}/sources/Outfit-500.ttf)}}
@font-face{{font-family:Outfit;font-weight:700;src:url(file://{B}/sources/Outfit-700.ttf)}}
@font-face{{font-family:Brico;font-weight:800;src:url(file://{B}/sources/BricolageGrotesque-800.ttf)}}
*{{margin:0;box-sizing:border-box}}
body{{width:1080px;height:1350px;font-family:Outfit,Inter,sans-serif;overflow:hidden;position:relative}}
.dark{{background:radial-gradient(circle at 85% 10%,#16407a 0,#0B1F3A 55%);color:#fff}}
.light{{background:#F4F7FD;color:#0B1F3A}}
.grad{{background:linear-gradient(150deg,#1D4ED8 0%,#0B1F3A 60%,#0B1F3A 100%);color:#fff}}
.hexbg{{position:absolute;right:-180px;top:-140px;width:720px;opacity:.07}}
.light .hexbg{{opacity:.06}}
.pad{{position:absolute;inset:90px 90px 0 90px}}
.chip{{display:inline-block;font-weight:700;font-size:28px;letter-spacing:.06em;text-transform:uppercase;padding:14px 26px;border-radius:999px;background:rgba(6,182,212,.16);color:#22D3EE;border:2px solid rgba(34,211,238,.45)}}
.light .chip{{background:#E0F7FB;color:#0E7490;border-color:#A5E8F3}}
h1{{font-weight:700;font-size:88px;line-height:1.05;margin-top:60px;letter-spacing:-.01em}}
.sous{{font-size:40px;line-height:1.35;margin-top:44px;color:#B9D3F5;font-weight:500}}
.light .sous{{color:#334A6B}}
.foot{{position:absolute;left:90px;right:90px;bottom:80px;display:flex;align-items:center;justify-content:space-between}}
.foot img{{height:64px}}
.cta{{font-weight:700;font-size:30px;padding:20px 34px;border-radius:16px;background:#06B6D4;color:#0B1F3A}}
.date{{font-family:Brico;font-size:34px;color:#22D3EE}}
.big{{font-family:Brico;font-weight:800;font-size:330px;line-height:.9;margin-top:120px;background:linear-gradient(90deg,#fff,#22D3EE);-webkit-background-clip:text;color:transparent}}
ul{{list-style:none;padding:0;margin-top:40px}}
li{{font-size:36px;line-height:1.3;margin:0 0 22px 0;padding-left:62px;position:relative;font-weight:500}}
li:before{{content:"✓";position:absolute;left:0;top:-2px;width:44px;height:44px;border-radius:50%;background:#1D4ED8;color:#fff;font-size:26px;display:flex;align-items:center;justify-content:center;font-weight:700}}
.phone{{margin-top:36px;background:#E6DDD4;border-radius:28px;padding:34px 30px;box-shadow:0 20px 50px rgba(11,31,58,.18)}}
.phone .hd{{font-size:24px;color:#5b6b7f;margin-bottom:18px;font-weight:700}}
.bubble{{background:#DCF8C6;border-radius:22px 22px 22px 6px;padding:26px 30px;font-size:33px;line-height:1.35;max-width:92%;color:#111}}
.light h1{{font-size:74px;margin-top:44px}}
"""
def page(cls, body, logo="nentix-horizontal-blanc.svg", foot_right=""):
    return f"""<!doctype html><html lang="fr"><meta charset="utf-8"><style>{CSS}</style>
<body class="{cls}"><img class="hexbg" src="file://{B}/logo/{'nentix-symbole.svg' if cls=='light' else 'nentix-symbole-blanc.svg'}">
<div class="pad">{body}</div>
<div class="foot"><img src="file://{B}/logo/{logo}">{foot_right}</div></body></html>"""
def build(p):
    t = p["type"]
    if t == "probleme":
        body = f'<span class="chip">{e(p["secteur"])}</span><h1 style="font-size:104px;margin-top:150px">{e(p["titre"])}</h1><p class="sous" style="font-size:44px;margin-top:60px">{e(p["sous"])}</p>'
        return page("dark", body, foot_right='<span class="date">Rendez-vous le 15.12</span>')
    if t == "teaser":
        body = f'<span class="chip">Bientôt à Libreville</span><div class="big">15.12</div><p class="sous" style="font-size:46px;color:#fff">{e(p["sous"])}</p>'
        return page("dark", body)
    if t == "apercu":
        pts = "".join(f"<li>{e(x)}</li>" for x in p["points"])
        body = (f'<span class="chip">{e(p["secteur"])}</span><h1>{e(p["titre"])}</h1><ul>{pts}</ul>'
                f'<div class="phone"><div class="hd">Message prêt à envoyer · WhatsApp</div><div class="bubble">{e(p["bulle"])}</div></div>')
        return page("light", body, logo="nentix-horizontal.svg", foot_right='<span class="cta">Places pilotes · 15.12</span>')
    if t == "compte":
        body = f'<span class="chip">Lancement le 15.12</span><div class="big">{e(p["gros"])}</div><h1 style="margin-top:40px">{e(p["titre"])}</h1><p class="sous">{e(p["sous"])}</p>'
        return page("dark", body, foot_right='<span class="cta">Écrivez « PILOTE » sur WhatsApp</span>')
    if t == "lancement":
        body = f'<span class="chip">15 décembre 2026</span><h1 style="font-size:120px;margin-top:120px">{e(p["titre"])}</h1><p class="sous" style="color:#fff;font-size:48px">{e(p["sous"])}</p><p class="sous">Salons · Salles de sport · Agences · Boutiques · Centres de formation</p>'
        return page("grad", body, foot_right='<span class="cta">Écrivez « ESSAI » sur WhatsApp</span>')
slug = lambda p: f'{p["n"]:02d}-{p["date"].split()[1].replace("/","-")}-{p["type"]}'
for p in POSTS:
    open(f"{OUT}/{slug(p)}.html","w").write(build(p))
# Calendrier markdown
md = ["# NENTIX : posts réseaux du 30/11 au 15/12/2026", "",
 "Format des affiches : 1080 × 1350 px (4:5, Facebook et Instagram). Dossier `affiches/`.",
 "Bouton du post Facebook : « Envoyer un message WhatsApp » (à régler dans Meta Business Suite).", "",
 "| # | Date | Heure | Type | Secteur | Affiche |", "|---|---|---|---|---|---|"]
for p in POSTS:
    md.append(f'| {p["n"]} | {p["date"]} | {p["heure"]} | {p["type"]} | {p.get("secteur","Tous")} | `affiches/{slug(p)}.png` |')
md += ["", "---", ""]
for p in POSTS:
    md += [f'## {p["n"]}. {p["date"]} à {p["heure"]} · {p.get("secteur","Tous secteurs")}', "",
           f'**Affiche :** `affiches/{slug(p)}.png`', "", "**Texte du post :**", "", p["texte"], "",
           (p["tags"]+" "+HASH).strip(), ""]
open(os.path.join(os.path.dirname(__file__),"..","calendrier-posts-30nov-15dec.md"),"w").write("\n".join(md))
print("\n".join(slug(p) for p in POSTS))

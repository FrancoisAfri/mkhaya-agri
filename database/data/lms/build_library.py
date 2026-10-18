"""Build database/data/lms/agri_training_library.json from the two verified research files.

Usage (from the app root): python3 -I database/data/lms/build_library.py database/data/lms/agri_skills_programmes.json database/data/lms/agri_qualifications.json database/data/lms/agri_training_library.json [database/data/lms/agri_livestock_extra.json database/data/lms/agri_crops_extra.json]\nOriginally: python3 -I build_library.py <skills.json> <qualifications.json|-> <out.json>
"""
import json
import re
import sys
from datetime import date

skills_path, quals_path, out_path = sys.argv[1:4]
# Optional: further verified research files ({"records": [...]}) — livestock species and crop enterprises.
extra_paths = sys.argv[4:]

# Grouped by prefix so the LMS category dropdown reads by animal and by crop.
CATEGORIES = [
    ("Health & Safety (Compliance)", "Training the OHS Act and farm safety practice expect: first aid, fire fighting, safety representatives, hazard identification.", "tabler-shield-check", "#EA5455"),
    ("Chemical Handling", "Safe application, storage and control of agrochemicals and animal-health products.", "tabler-flask", "#FF9F43"),
    ("Livestock · Cattle (Beef)", "Beef cattle: handling, feeding, calving, feedlot, identification.", "tabler-paw", "#A1662F"),
    ("Livestock · Dairy", "Dairy cows: milking, milk hygiene, calf rearing.", "tabler-milk", "#A1662F"),
    ("Livestock · Sheep & Goats", "Sheep and goats: lambing and kidding, shearing, wool and mohair.", "tabler-paw", "#A1662F"),
    ("Livestock · Poultry", "Chickens and other poultry: broilers, layers, hatchery, egg handling.", "tabler-egg", "#A1662F"),
    ("Livestock · Pigs", "Pig husbandry: farrowing, weaners, growers and finishers.", "tabler-paw", "#A1662F"),
    ("Livestock · Ostrich", "Ostrich production.", "tabler-feather", "#A1662F"),
    ("Livestock · Game & Wildlife", "Game ranching and wildlife on farms.", "tabler-deer", "#A1662F"),
    ("Livestock · Horses", "Horses and equine care.", "tabler-horse-toy", "#A1662F"),
    ("Livestock · Rabbits", "Rabbit production.", "tabler-paw", "#A1662F"),
    ("Livestock · Aquaculture", "Fish and shellfish farming.", "tabler-fish", "#00BAD1"),
    ("Livestock · Bees", "Beekeeping and pollination.", "tabler-bug", "#FFB400"),
    ("Livestock · Animal Health & Welfare", "Animal health, dosing and vaccination, breeding, welfare and humane handling across all species.", "tabler-stethoscope", "#A1662F"),
    ("Livestock · General Husbandry", "Animal husbandry skills that apply to every species: handling, feeding, observation, breeding.", "tabler-paw", "#A1662F"),
    ("Livestock · Meat & Abattoir", "Meat examination and slaughter hygiene.", "tabler-meat", "#A1662F"),
    ("Crops · Vegetables", "Open-field vegetable production.", "tabler-carrot", "#28C76F"),
    ("Crops · Protected Cultivation & Hydroponics", "Tunnels, greenhouses and hydroponics.", "tabler-building-warehouse", "#28C76F"),
    ("Crops · Fruit & Orchards", "Deciduous, citrus, subtropical fruit and berries.", "tabler-apple", "#28C76F"),
    ("Crops · Grains & Oilseeds", "Maize, wheat, soybean, sunflower and other field crops.", "tabler-wheat", "#28C76F"),
    ("Crops · Sugarcane", "Sugarcane production.", "tabler-plant", "#28C76F"),
    ("Crops · Vineyards", "Viticulture and table/wine grapes.", "tabler-glass-full", "#28C76F"),
    ("Crops · Nursery & Propagation", "Plant propagation and nursery work.", "tabler-seeding", "#28C76F"),
    ("Crops · Organic & Conservation Agriculture", "Organic, conservation and regenerative farming.", "tabler-leaf", "#28C76F"),
    ("Crops · Soil & Fertility", "Soil preparation, fertility and plant nutrition.", "tabler-shovel", "#28C76F"),
    ("Crops · Pastures & Fodder", "Planted pastures, hay and silage.", "tabler-grass", "#28C76F"),
    ("Crops · Specialty Crops", "Mushrooms, herbs, medicinal plants and other specialty crops.", "tabler-mushroom", "#28C76F"),
    ("Crops · General Crop Production", "Crop skills that apply across crops: planting, pest, disease and weed control, harvesting.", "tabler-plant-2", "#28C76F"),
    ("Mixed Farming", "Qualifications combining plant and animal production.", "tabler-tractor", "#00CFE8"),
    ("Post-Harvest & Packhouse", "Packing, grading, storage and cold chain of produce.", "tabler-package", "#FFB400"),
    ("Agri-Processing & Food Safety", "Processing of agricultural produce, hygiene and food safety.", "tabler-building-factory-2", "#FFB400"),
    ("Machinery & Equipment", "Tractors, implements, forklifts, chainsaws and hand tools.", "tabler-tool", "#7367F0"),
    ("Irrigation & Water", "Irrigation systems, farm water use and soil and water conservation.", "tabler-droplet", "#00BAD1"),
    ("Agri-Business & Marketing", "Farm business, finance, cooperatives and marketing of produce.", "tabler-briefcase", "#4B4B4B"),
    ("Farm Management & Supervision", "Managing farms and supervising farm teams.", "tabler-users", "#4B4B4B"),
    ("Extension & Smallholder Farming", "Agricultural extension and smallholder / subsistence farming.", "tabler-home-heart", "#4B4B4B"),
    ("Other Agriculture", "Other registered agricultural training.", "tabler-leaf", "#82868B"),
]
# Categories earlier versions of the library used. A farm's library course still filed under one of
# these is moved to its new category on the next install; a course the farm re-filed is left alone.
RETIRED = ["Livestock", "Crop Production", "Irrigation"]

# Keyword rules for SAQA titles: first match wins. Species/enterprise before the general buckets.
RULES = [
    (r"meat exam|abattoir|slaughter", "Livestock · Meat & Abattoir"),
    (r"aquacultur|\bfish|shellfish|mussel|oyster|abalone", "Livestock · Aquaculture"),
    (r"\bbee|apicultur|honey", "Livestock · Bees"),
    (r"poultry|broiler|layer|hatcher|\begg|chicken", "Livestock · Poultry"),
    (r"\bpig|swine|porcine|farrow", "Livestock · Pigs"),
    (r"dairy|milk", "Livestock · Dairy"),
    (r"\bsheep|\bgoat|wool|mohair|shear|lamb|small stock", "Livestock · Sheep & Goats"),
    (r"ostrich", "Livestock · Ostrich"),
    (r"\bgame\b|wildlife", "Livestock · Game & Wildlife"),
    (r"equine|horse", "Livestock · Horses"),
    (r"rabbit", "Livestock · Rabbits"),
    (r"cattle|\bbeef|feedlot|calv|\bcow", "Livestock · Cattle (Beef)"),
    (r"animal health|veterinar|disease.*animal|dos(e|ing)|vaccinat|insemination|welfare|livestock processing treat", "Livestock · Animal Health & Welfare"),
    (r"animal|livestock|husbandry|breeding", "Livestock · General Husbandry"),
    (r"hydropon|tunnel|greenhouse|protected", "Crops · Protected Cultivation & Hydroponics"),
    (r"vegetable", "Crops · Vegetables"),
    (r"orchard|fruit|citrus|deciduous|subtropical|avocado|mango|macadamia|banana|berr", "Crops · Fruit & Orchards"),
    (r"grain|maize|wheat|soybean|sunflower|oilseed", "Crops · Grains & Oilseeds"),
    (r"sugarcane|sugar cane", "Crops · Sugarcane"),
    (r"vine|viticult|grape", "Crops · Vineyards"),
    (r"nursery|propagat|seedling", "Crops · Nursery & Propagation"),
    (r"organic|conservation agri|regenerat", "Crops · Organic & Conservation Agriculture"),
    (r"pasture|fodder|hay|silage|forage", "Crops · Pastures & Fodder"),
    (r"mushroom|herb|medicinal", "Crops · Specialty Crops"),
    (r"soil|fertili[sz]|plant nutrition", "Crops · Soil & Fertility"),
    (r"pack(ing|house)|post-harvest|grading|cold chain", "Post-Harvest & Packhouse"),
    (r"irrigat|water", "Irrigation & Water"),
    (r"crop|plant|pest|weed|harvest|prun|planting|cultivat", "Crops · General Crop Production"),
]
SPECIES_MAP = {  # research files' own labels -> library categories
    "Cattle": "Livestock · Cattle (Beef)", "Dairy": "Livestock · Dairy", "Sheep & Goats": "Livestock · Sheep & Goats",
    "Poultry": "Livestock · Poultry", "Pigs": "Livestock · Pigs", "Ostrich": "Livestock · Ostrich",
    "Game & Wildlife": "Livestock · Game & Wildlife", "Horses": "Livestock · Horses", "Rabbits": "Livestock · Rabbits",
    "Aquaculture": "Livestock · Aquaculture", "Bees": "Livestock · Bees", "Animal Health & Welfare": "Livestock · Animal Health & Welfare",
    "Livestock — General": "Livestock · General Husbandry",
    "Vegetables": "Crops · Vegetables", "Protected Cultivation & Hydroponics": "Crops · Protected Cultivation & Hydroponics",
    "Fruit & Orchards": "Crops · Fruit & Orchards", "Grains & Oilseeds": "Crops · Grains & Oilseeds", "Sugarcane": "Crops · Sugarcane",
    "Vineyards": "Crops · Vineyards", "Nursery & Propagation": "Crops · Nursery & Propagation",
    "Organic & Conservation Agriculture": "Crops · Organic & Conservation Agriculture", "Soil & Fertility": "Crops · Soil & Fertility",
    "Pastures & Fodder": "Crops · Pastures & Fodder", "Mushrooms, Herbs & Specialty Crops": "Crops · Specialty Crops",
    "Post-Harvest & Packhouse": "Post-Harvest & Packhouse", "Agri-Business & Marketing": "Agri-Business & Marketing",
    "Extension & Smallholder Farming": "Extension & Smallholder Farming", "Farm Mechanisation & Irrigation": "Irrigation & Water",
    "Environment & Water": "Irrigation & Water", "Crop Production — General": "Crops · General Crop Production",
}


QUAL_CATEGORY = {  # research labels that need no keyword rule
    "Mixed Farming": "Mixed Farming",
    "Agri-Processing": "Agri-Processing & Food Safety",
    "Agricultural Management & Extension": "Farm Management & Supervision",
    "Agricultural Machinery": "Machinery & Equipment",
    "Other Agriculture": "Other Agriculture",
}


def categorise(title, fallback):
    """The library category for a course: species / enterprise from its title, else the given bucket."""
    if fallback in ("Livestock", "Crop Production", "Irrigation", "Animal Production", "Plant Production", "Animal Health", None):
        for pattern, name in RULES:
            if re.search(pattern, title, re.I):
                return name
        return {"Livestock": "Livestock · General Husbandry", "Animal Production": "Livestock · General Husbandry",
                "Animal Health": "Livestock · Animal Health & Welfare", "Irrigation": "Irrigation & Water"}.get(fallback, "Crops · General Crop Production")
    return fallback


SETAS = ["AgriSETA", "HWSETA", "SASSETA", "MERSETA", "FOODBEV SETA", "FP&M SETA", "TETA", "CHIETA", "MQA", "EWSETA", "CETA"]


def seta_of(*texts):
    """The SETA a record names, if any; agricultural standards-body origin counts as AgriSETA."""
    blob = " ".join(t for t in texts if t)
    for s in SETAS:
        if re.search(re.escape(s), blob, re.I):
            return s
    if re.search(r"agricultur", blob, re.I):
        return "AgriSETA"
    return None


def us_status(text):
    """SAQA's wording, reduced to the register's codes: registered | reregistered | archived."""
    t = (text or "").lower()
    if "reregistered" in t:
        return "reregistered"
    if "registered" in t:
        return "registered"
    return "archived" if t else "registered"


def clean_date(v):
    return v if isinstance(v, str) and re.fullmatch(r"\d{4}-\d{2}-\d{2}", v) else None


unit_standards, courses = {}, []


def as_text(v):
    if not v:
        return ""
    if isinstance(v, list):
        return "; ".join(" ".join(p for p in (str(x.get("saqa_id") or ""), x.get("title") or "") if p) if isinstance(x, dict) else str(x) for x in v)
    return str(v)


def qual_note(q):
    parts = []
    if q.get("replaces"):
        parts.append("Replaces SAQA " + as_text(q["replaces"]) + ".")
    if q.get("replaced_by"):
        parts.append("Replaced by SAQA " + as_text(q["replaced_by"]) + ".")
    if q.get("notes"):
        parts.append(as_text(q["notes"]))
    return " ".join(parts) or None

skills = json.load(open(skills_path))["unit_standards"]
for u in skills:
    sid = str(u["us_id"])
    seta = seta_of(u.get("quality_assurer"), u.get("originator"))
    unit_standards[sid] = {
        "saqa_id": sid,
        "title": u["title"],
        "nqf_level": u.get("nqf_level"),
        "credits": u.get("credits"),
        "type": "unit_standard",
        "status": us_status(u.get("registration_status")),
        "registered_at": clean_date(u.get("registration_start_date")),
        "expires_at": clean_date(u.get("registration_end_date")),
        "purpose": u.get("purpose"),
        "seta": seta,
        "outcomes": u.get("specific_outcomes") or [],
    }
    is_compliance = u.get("group") == "compliance"
    category = "Health & Safety (Compliance)" if is_compliance and u.get("category") == "Health & Safety" else u.get("category") or "Other Agriculture"
    if category == "Other":
        category = "Other Agriculture"
    category = categorise(u["title"], category)
    notes = [n for n in (u.get("refresh_note"), u.get("regulatory_note")) if n]
    courses.append({
        "key": f"US-{sid}",
        "kind": "compliance" if is_compliance else "skills_programme",
        "title": u["title"],
        "category": category,
        "purpose": u.get("purpose"),
        "saqa_id": sid,
        "unit_standard_code": sid,
        "nqf_level": u.get("nqf_level"),
        "credits": u.get("credits"),
        "quality_assurer": u.get("quality_assurer"),
        "seta": seta,
        "registration_status": u.get("registration_status"),
        "last_date_for_enrolment": clean_date(u.get("last_date_for_enrolment")),
        "last_date_for_achievement": clean_date(u.get("last_date_for_achievement")),
        "enrolment_closed": bool(u.get("enrolment_closed")),
        "is_mandatory": bool(u.get("mandatory")) or is_compliance,
        "refresh_months": u.get("refresh_months"),
        "legal_basis": u.get("legal_basis"),
        "note": " ".join(notes) or None,
        "source_url": u.get("source_url"),
        "modules": [{"unit_standard_id": sid, "title": u["title"], "type": "Unit standard",
                     "nqf_level": u.get("nqf_level"), "credits": u.get("credits")}],
    })

qual_count = 0
if quals_path != "-":
    for q in json.load(open(quals_path))["qualifications"]:
        qid = str(q["saqa_id"])
        seta = seta_of(q.get("quality_assurer"))
        modules = []
        for us in q.get("unit_standards") or []:
            usid = str(us.get("us_id"))
            unit_standards.setdefault(usid, {
                "saqa_id": usid, "title": us.get("title"), "nqf_level": us.get("nqf_level"),
                # SAQA's component list carries no status of its own; it shares its qualification's.
                "credits": us.get("credits"), "type": "unit_standard", "status": us_status(q.get("registration_status")),
                "registered_at": None, "expires_at": None, "purpose": None, "seta": seta, "outcomes": [],
            })
            modules.append({"unit_standard_id": usid, "title": us.get("title"), "type": us.get("type"),
                            "nqf_level": us.get("nqf_level"), "credits": us.get("credits")})
        for subj in q.get("subjects") or []:
            t = subj if isinstance(subj, str) else (subj.get("title") or subj.get("name") or "")
            if t:
                modules.append({"code": None if isinstance(subj, str) else subj.get("code"), "title": t, "type": "Subject"})
        for m in q.get("modules") or []:
            modules.append({"code": m.get("code"), "title": m.get("title"), "type": m.get("type"),
                            "nqf_level": m.get("nqf_level"), "credits": m.get("credits")})
        # Fundamentals first, then core, then electives — the order a learner meets them.
        rank = {"Fundamental": 0, "Knowledge": 0, "Core": 1, "Practical Skills": 1, "Elective": 2, "Work Experience": 2}
        modules.sort(key=lambda m: rank.get(m.get("type") or "", 1))
        courses.append({
            "key": f"QUAL-{qid}",
            "kind": "qualification",
            "title": q["title"],
            "category": categorise(q["title"], q.get("category")) if q.get("category") in ("Plant Production", "Animal Production", "Animal Health")
                        else QUAL_CATEGORY.get(q.get("category"), "Other Agriculture"),
            "purpose": q.get("purpose"),
            "saqa_id": qid,
            "nqf_level": q.get("nqf_level"),
            "credits": q.get("credits"),
            "ofo_code": q.get("ofo_code"),
            "quality_assurer": q.get("quality_assurer"),
            "seta": seta,
            "is_qcto_registered": q.get("framework") == "qcto_occupational",
            "registration_status": q.get("registration_status"),
            "last_date_for_enrolment": clean_date(q.get("last_date_for_enrolment")),
            "last_date_for_achievement": clean_date(q.get("last_date_for_achievement")),
            "enrolment_closed": bool(clean_date(q.get("last_date_for_enrolment")) and clean_date(q.get("last_date_for_enrolment")) < date.today().isoformat()),
            "is_mandatory": False,
            "note": qual_note(q),
            "source_url": q.get("source_url"),
            "modules": modules,
        })
        qual_count += 1

# Further verified research ({"records": [...]}): species and enterprise training. A record whose SAQA
# ID is already in the library is skipped, so files can overlap without duplicating a course.
held = {c["saqa_id"] for c in courses}
extra_count = 0
for path in extra_paths:
    for r in json.load(open(path)).get("records", []):
        rid = str(r.get("saqa_id") or "")
        if not rid or rid in held:
            continue
        held.add(rid)
        label = r.get("species") or r.get("enterprise")
        category = SPECIES_MAP.get(label) or categorise(r["title"], None)
        seta = seta_of(r.get("quality_assurer"), r.get("originator"))
        if r.get("kind") == "unit_standard":
            unit_standards[rid] = {
                "saqa_id": rid, "title": r["title"], "nqf_level": r.get("nqf_level"), "credits": r.get("credits"),
                "type": "unit_standard", "status": us_status(r.get("registration_status")),
                "registered_at": clean_date(r.get("registration_start_date")), "expires_at": clean_date(r.get("registration_end_date")),
                "purpose": r.get("purpose"), "seta": seta, "outcomes": r.get("specific_outcomes") or [],
            }
            closes = clean_date(r.get("last_date_for_enrolment"))
            courses.append({
                "key": f"US-{rid}", "kind": "skills_programme", "title": r["title"], "category": category,
                "purpose": r.get("purpose"), "saqa_id": rid, "unit_standard_code": rid,
                "nqf_level": r.get("nqf_level"), "credits": r.get("credits"), "quality_assurer": r.get("quality_assurer"),
                "seta": seta, "registration_status": r.get("registration_status"),
                "last_date_for_enrolment": closes, "last_date_for_achievement": clean_date(r.get("last_date_for_achievement")),
                "enrolment_closed": bool(closes and closes < date.today().isoformat()),
                "is_mandatory": False, "note": as_text(r.get("notes")) or None, "source_url": r.get("source_url"),
                "modules": [{"unit_standard_id": rid, "title": r["title"], "type": "Unit standard",
                             "nqf_level": r.get("nqf_level"), "credits": r.get("credits")}],
            })
        else:
            q = dict(r, category=None)
            modules = []
            for us in r.get("unit_standards") or []:
                usid = str(us.get("us_id"))
                unit_standards.setdefault(usid, {
                    "saqa_id": usid, "title": us.get("title"), "nqf_level": us.get("nqf_level"), "credits": us.get("credits"),
                    "type": "unit_standard", "status": us_status(r.get("registration_status")),
                    "registered_at": None, "expires_at": None, "purpose": None, "seta": seta, "outcomes": [],
                })
                modules.append({"unit_standard_id": usid, "title": us.get("title"), "type": us.get("type"),
                                "nqf_level": us.get("nqf_level"), "credits": us.get("credits")})
            for m in r.get("modules") or []:
                modules.append({"code": m.get("code"), "title": m.get("title"), "type": m.get("type"),
                                "nqf_level": m.get("nqf_level"), "credits": m.get("credits")})
            rank = {"Fundamental": 0, "Knowledge": 0, "Core": 1, "Practical Skills": 1, "Elective": 2, "Work Experience": 2}
            modules.sort(key=lambda m: rank.get(m.get("type") or "", 1))
            closes = clean_date(r.get("last_date_for_enrolment"))
            courses.append({
                "key": f"QUAL-{rid}", "kind": "qualification", "title": r["title"], "category": category,
                "purpose": r.get("purpose"), "saqa_id": rid, "nqf_level": r.get("nqf_level"), "credits": r.get("credits"),
                "quality_assurer": r.get("quality_assurer"), "seta": seta,
                "is_qcto_registered": "occupational" in (r.get("title") or "").lower() or r.get("framework") == "qcto_occupational",
                "registration_status": r.get("registration_status"),
                "last_date_for_enrolment": closes, "last_date_for_achievement": clean_date(r.get("last_date_for_achievement")),
                "enrolment_closed": bool(closes and closes < date.today().isoformat()),
                "is_mandatory": False, "note": qual_note(q), "source_url": r.get("source_url"), "modules": modules,
            })
            qual_count += 1
        extra_count += 1

# SAQA's QCTO module titles sometimes carry a cut-off tail ("…poultry farming industry, at", "…Equipment;").
def clean_title(t):
    return re.sub(r"(\s*[;,]\s*(at)?)+\s*$", "", (t or "").strip())


for c in courses:
    for i, m in enumerate(c["modules"], 1):
        m["title"] = clean_title(m.get("title"))
        # A module SAQA lists without a code still needs a stable key for its lessons.
        if not m.get("unit_standard_id") and not m.get("code") and c["kind"] == "qualification":
            m["code"] = f"Q{c['saqa_id']}-M{i:02d}"
            m["internal_code"] = True

# A qualification SAQA lists no components for (higher-education diplomas, NC: Farming L4) gets one
# overview module, so it still has somewhere for its lessons to live.
for c in courses:
    if c["kind"] == "qualification" and not c["modules"]:
        c["modules"] = [{"code": f"QUAL{c['saqa_id']}", "internal_code": True, "title": c["title"], "type": "Overview",
                         "nqf_level": c.get("nqf_level"), "credits": c.get("credits")}]

# Compliance first, then skills programmes, then qualifications; alphabetical within each.
order = {"compliance": 0, "skills_programme": 1, "qualification": 2}
courses.sort(key=lambda c: (order[c["kind"]], c["category"], c["title"].lower()))

used = {c["category"] for c in courses}
library = {
    "version": 2,
    "retired_categories": RETIRED,
    "generated_at": date.today().isoformat(),
    "sources": "Each course and unit standard was checked against its own page on the SAQA register (regqs.saqa.org.za); see source_url.",
    "categories": [{"name": n, "description": d, "icon": i, "color": c} for n, d, i, c in CATEGORIES if n in used],
    "unit_standards": sorted(unit_standards.values(), key=lambda u: int(u["saqa_id"]) if u["saqa_id"].isdigit() else 0),
    "courses": courses,
}
json.dump(library, open(out_path, "w"), indent=1, ensure_ascii=False)
print(f"{len(courses)} courses ({sum(c['kind']=='compliance' for c in courses)} compliance, "
      f"{sum(c['kind']=='skills_programme' for c in courses)} skills programmes, {qual_count} qualifications), "
      f"{len(unit_standards)} unit standards, {len(library['categories'])} categories; {extra_count} from extra research files")

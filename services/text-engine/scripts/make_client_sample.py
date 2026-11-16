"""Generate samples/client_sample.docx: a full multi-page demonstration of the
client template, produced by the real exporter (``export_docx``).

    python scripts/make_client_sample.py

The prose is written for this sample (a generic lecture on seeking knowledge);
nothing is taken from any client file. Ordinary text is typed the way an editor
types it: unvowelled, with tanween where needed and the fixed formulas left
plain, so the output shows the exporter vowelling the formulas by itself.
Quran verses and hadith are well-known public texts, fully vowelled.
"""

from __future__ import annotations

import random
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from mofaregh_text.export import export_docx  # noqa: E402

TITLE = "مجلس في فضل العلم"

OPENING = [
    "بسم الله، والصلاة والسلام على رسول الله صلى الله عليه وسلم.",
    "موضوع مجلسنا اليوم هو العلم: منزلته، وطريق تحصيله، وما ينبغي لطالبه أن يتحلى به.",
    "وسنقسم الكلام إلى ثلاثة محاور، ثم نختم بوصايا عملية يسهل تطبيقها.",
]

SENTENCES = [
    "طلب العلم ليس جمعًا للمعلومات فقط، بل هو طريق يغيِّر صاحبه قبل أن يغيِّر غيره",
    "ومن أراد أن يثبت على هذا الطريق فعليه أن يصحح نيته أولًا، ثم يسأل الله تبارك وتعالى العون والتوفيق",
    "إذًا المسألة ليست في كثرة الكتب ولا في عدد الدروس، وإنما في صدق الطلب وحسن القصد",
    "والنبي صلى الله عليه وسلم كان يعلِّم أصحابه شيئًا فشيئًا، حتى يستقر العلم في قلوبهم ويظهر في أعمالهم",
    "ولذلك كان السلف يتعلمون الأدب قبل أن يتعلموا العلم، ويرون أن الأدب نصف الطريق",
    "فمن استعجل الثمرة قبل أوانها حُرم منها، ومن صبر على مرارة البداية ذاق حلاوة النهاية",
    "وهذا الأمر مهم جدًّا، لأن كثيرًا من الناس يبدأ بحماس ثم ينقطع بعد أيام قليلة",
    "والسبب في ذلك أنه لم يضع لنفسه منهجًا واضحًا، ولم يتخذ له معلمًا يرشده ويقوِّم خطأه",
    "أيضًا من الآداب المهمة أن يحترم الطالب وقته، فالوقت هو رأس ماله الحقيقي",
    "وأن يعلم أن الله تبارك وتعالى يبارك في القليل إذا صحبه الإخلاص والمداومة",
    "وقد كان الإمام الشافعي رحمه الله يقسم ليله أثلاثًا، ثلثًا للعلم وثلثًا للصلاة وثلثًا للنوم",
    "فانظروا كيف كان حرصهم على أوقاتهم، وكيف صار العلم عندهم عبادة يتقربون بها إلى الله جل وعلا",
    "ومن الآداب كذلك أن يعمل الطالب بما علم، فالعلم بلا عمل حجة على صاحبه",
    "وأن يتواضع لمن يتعلم منه، وألَّا يتكبر على من هو دونه في العلم",
    "وكانت عائشة رضي الله عنها مرجعًا للصحابة في كثير من المسائل، يسألونها فتجيبهم بعلم وفهم",
    "ثم إن المذاكرة حياة العلم، فمن لم يراجع ما حفظ ضاع منه شيئًا فشيئًا",
    "ولا بد من الصحبة الصالحة التي تعين على الخير وتذكِّر به إذا نسي صاحبه",
    "فالإنسان ضعيف بنفسه قوي بإخوانه، وهذا أمر مجرَّب معروف",
    "وقد ذكر موسى عليه السلام في رحلته لطلب العلم أدبًا عظيمًا مع معلمه، فاستأذن وتلطف في السؤال",
    "والمقصود أن العلم يحتاج إلى صبر طويل، وإلى نفس لا تمل ولا تستعجل",
    "ومن اعتذر بكثرة أشغاله فليجعل لنفسه وردًا يسيرًا من القراءة كل يوم ولا يتركه",
    "فإن القليل الدائم خير من الكثير المنقطع، وهذه قاعدة نافعة في كل أبواب الخير",
    "ومما يقطع الطالب عن غايته أن يشتغل بما لا ينفعه، أو يتنقل بين الفنون دون أن يتقن واحدًا منها",
    "ومن عرف قدر ما يطلب هان عليه ما يبذل، فالجنة سلعة الله الغالية",
    "وكل هذا لا يكون إلا بتوفيق الله تبارك وتعالى، فأكثروا من الدعاء وسؤال الله الثبات",
    "وإذا فتح الله عليك بابًا من العلم فاشكره عليه، واعلم أنه فضل منه سبحانه لا بحولك ولا بقوتك",
    "فهذه وصايا مختصرة، أسأل الله أن ينفعني وإياكم بها، وأن يجعلها حجة لنا لا علينا",
    "والعلم رحم بين أهله، فاحفظوا لأهل العلم حقهم وادعوا لهم، رحمهم الله أحياءً وأمواتًا",
]

QURAN = [  # (intro, text, surah, ayah)
    ("والله يقول في كتابه:", "وَقُلْ رَبِّ زِدْنِي عِلْمًا", 20, 114),
    ("قال الله تبارك وتعالى:", "إِنَّمَا يَخْشَى اللَّهَ مِنْ عِبَادِهِ الْعُلَمَاءُ", 35, 28),
    ("يقول الله تبارك وتعالى:", "يَرْفَعِ اللَّهُ الَّذِينَ آمَنُوا مِنْكُمْ وَالَّذِينَ أُوتُوا الْعِلْمَ دَرَجَاتٍ", 58, 11),
    ("والله يقول:", "قُلْ هَلْ يَسْتَوِي الَّذِينَ يَعْلَمُونَ وَالَّذِينَ لَا يَعْلَمُونَ", 39, 9),
    ("وقد قال سبحانه:", "فَاسْأَلُوا أَهْلَ الذِّكْرِ إِنْ كُنْتُمْ لَا تَعْلَمُونَ", 16, 43),
    ("وأول ما نزل من القرآن:", "اقْرَأْ بِاسْمِ رَبِّكَ الَّذِي خَلَقَ", 96, 1),
    ("قال الله جل وعلا:", "وَاتَّقُوا اللَّهَ وَيُعَلِّمُكُمُ اللَّهُ", 2, 282),
]

HADITH = [  # (intro, text)
    ("يقول النبي صلى الله عليه وسلم:", "مَنْ سَلَكَ طَرِيقًا يَلْتَمِسُ فِيهِ عِلْمًا سَهَّلَ اللَّهُ لَهُ بِهِ طَرِيقًا إِلَى الْجَنَّةِ"),
    ("وفي الحديث أن النبي صلى الله عليه وسلم قال:", "إِنَّمَا الْأَعْمَالُ بِالنِّيَّاتِ"),
    ("وقال صلى الله عليه وسلم:", "طَلَبُ الْعِلْمِ فَرِيضَةٌ عَلَى كُلِّ مُسْلِمٍ"),
    ("وفي البخاري أن النبي صلى الله عليه وسلم قال:", "خَيْرُكُمْ مَنْ تَعَلَّمَ الْقُرْآنَ وَعَلَّمَهُ"),
]

BULLETS = [
    ("ومحاور هذا المجلس ثلاثة:", ["منزلة العلم وأهله.", "الطريق إلى تحصيله.", "ما يتحلى به طالبه."]),
    ("ومما يعين على الحفظ:", ["اختيار وقت يصفو فيه الذهن.", "تكرار المحفوظ على فترات متباعدة."]),
    ("ويُنصح المبتدئ بأمور، منها:", ["أن يبدأ بالمختصرات قبل المطولات.", "أن يقيِّد الفوائد في دفتر خاص.", "أن يعرض ما فهمه على من هو أعلم منه."]),
]

POEM = (
    "ومما يُروى عن الإمام الشافعي رحمه الله أنه قال:",
    ["شَكَوتُ إِلى وَكيعٍ سوءَ حِفظي", "\t\tفَأَرشَدَني إِلى تَركِ المَعاصي",
     "", "وَأَخبَرَني بِأَنَّ العِلمَ نورٌ", "\t\tوَنورُ اللَهِ لا يُهدى لِعاصي"],
)

CLOSING = [
    "هذا ما تيسر ذكره في هذا المجلس، ونكمل في المجلس القادم بإذن الله.",
    "والله أعلم، وصلى الله عليه وسلم على نبينا محمد.",
]


def build_document(paragraphs: int = 175, seed: int = 7) -> dict:
    rnd = random.Random(seed)
    segments: list[dict] = []

    def seg(blocks: list[dict]) -> None:
        start = len(segments) * 60_000  # > lecture_paragraph_gap_ms apart: one paragraph each
        segments.append({"id": f"s{len(segments) + 1}", "start_ms": start, "end_ms": start + 20_000,
                         "speaker_id": "sp1", "blocks": blocks})

    def text(t: str) -> dict:
        return {"type": "text", "text": t}

    def prose(n: int) -> str:
        return "، ".join(rnd.sample(SENTENCES, n)) + "."

    for line in OPENING:
        seg([text(line)])
    specials = (["quran"] * 3 + ["hadith"] * 2 + ["bullets", "poem"])
    q = h = b = 0
    poem_done = False
    for i in range(paragraphs):
        kind = specials[(i // 6) % len(specials)] if i % 6 == 5 else "prose"
        if kind == "quran":
            intro, verse, surah, ayah = QURAN[q % len(QURAN)]
            q += 1
            seg([text(prose(1)[:-1] + "، " + intro),
                 {"type": "quran", "surah": surah, "ayah_start": ayah, "ayah_end": ayah,
                  "script": "imlaei", "word_start": None, "word_end": None, "text": verse},
                 text("، " + prose(rnd.randint(1, 2)))])
        elif kind == "hadith":
            intro, wording = HADITH[h % len(HADITH)]
            h += 1
            seg([text(intro), {"type": "hadith", "match": "exact", "text": wording},
                 text("، " + prose(rnd.randint(1, 2)))])
        elif kind == "bullets":
            intro, items = BULLETS[b % len(BULLETS)]
            b += 1
            seg([text(intro + "\n" + "\n".join("• " + it for it in items))])
        elif kind == "poem" and not poem_done:
            poem_done = True
            seg([text(POEM[0] + "\n" + "\n".join(POEM[1]))])
        else:
            seg([text(prose(rnd.choice([1, 1, 2, 2, 3, 4])))])
    for line in CLOSING:
        seg([text(line)])

    return {
        "version": 1,
        "title": TITLE,
        "source_audio": {"file_name": "sample.mp3", "duration_ms": len(segments) * 60_000},
        "settings": {"tashkeel": "partial", "quran_script": "imlaei",
                     "quran_reference_format": "[{surah_name}: {ayah_range}]"},
        "speakers": [{"id": "sp1", "name": "المحاضر"}],
        "segments": segments,
    }


def main() -> None:
    out = ROOT / "samples" / "client_sample.docx"
    out.parent.mkdir(exist_ok=True)
    out.write_bytes(export_docx(build_document()))  # default options = client template
    print("wrote", out)


if __name__ == "__main__":
    main()

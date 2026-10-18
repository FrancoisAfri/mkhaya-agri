// Dashboard (home_page) translations for the SA languages that use English as
// their base locale. These are best-effort drafts — the high-visibility strings
// a user sees on the dashboard. Any key omitted here falls back to English via
// the deep-merge in i18n.ts, so partial coverage is safe. Native-speaker review
// is welcome to refine wording.

type HomePage = Record<string, any>

// isiXhosa
const xh: HomePage = {
    greeting: { good_morning: "Molo kusasa", good_afternoon: "Molo emini", good_evening: "Molo ngokuhlwa", welcome_back: "Wamkelekile kwakhona, {name}!" },
    hero: { featured: "Okuqaqambileyo", ceo_title: "Umyalezo ovela ku-CEO", ceo_fallback: "Hlala ulindile umyalezo obalulekileyo ovela ku-CEO wethu.", read_more: "Funda ngokugqithisileyo", read_full_message: "Funda umyalezo opheleleyo", video_tag: "Ividiyo" },
    quick_access: { title: "Ufikelelo olukhawulezayo", customize: "Lungisa", add_shortcuts: "Cofa uLungise ukongeza iindlela ezimfutshane", done: "Kwenziwe" },
    top_resources: { title: "Izixhobo eziphambili", subtitle: "Ufikelelo olukhawulezayo kwiifom, iitemplethi kunye nemigaqo-nkqubo", search_placeholder: "Khangela iifom neetemplethi...", show_more: "Bonisa ezinye izixhobo ezi-6", show_less: "Bonisa okungaphantsi" },
    news_events: { title: "Iindaba neZiganeko", subtitle: "Hlala usazi ngezona ndaba zitsha zenkampani", filter_news: "Iindaba", filter_announcements: "Izibhengezo", filter_events: "Iziganeko", filter_internal_notice: "Isaziso sangaphakathi", filter_anniversaries: "Iminyaka", filter_leaves: "Iikhefu", no_content: "Akukho nkcazelo ikhoyo okwangoku.", no_upcoming_events: "Akukho ziganeko zizayo okwangoku.", read_more: "Funda ngokugqithisileyo", calendar: "Ikhalenda", upcoming_events: "Iziganeko ezizayo", no_upcoming_contents: "Akukho nkcazelo izayo", company_event: "Isiganeko senkampani" },
    spotlights: { title: "Ukuqaqambisa abasebenzi neeqela", subtitle: "Sibhiyozela abantu bethu kunye nempumelelo yabo", view_details: "Jonga iinkcukacha", congratulate: "Vuyisana", close: "Vala" },
}

// Sepedi / Sesotho sa Leboa
const nso: HomePage = {
    greeting: { good_morning: "Thobela mesong", good_afternoon: "Thobela mosegare", good_evening: "Thobela mantšiboa", welcome_back: "Re a go amogela gape, {name}!" },
    hero: { featured: "Tše di tšwelelago", ceo_title: "Molaetša go tšwa go CEO", ceo_fallback: "Dula o letetše molaetša wa bohlokwa go tšwa go CEO wa rena.", read_more: "Bala go feta", read_full_message: "Bala molaetša ka botlalo", video_tag: "Bidio" },
    quick_access: { title: "Phihlelelo ya ka pela", customize: "Fetola", add_shortcuts: "Kgotla Fetola go oketša dikgaufsi", done: "Go feditšwe" },
    top_resources: { title: "Didirišwa tše dikgolo", subtitle: "Phihlelelo ya ka pela go diforomo, ditemplete le melawana", search_placeholder: "Nyaka diforomo le ditemplete...", show_more: "Bontšha didirišwa tše dingwe tše 6", show_less: "Bontšha tše nnyane" },
    news_events: { title: "Ditaba le Ditiragalo", subtitle: "Dula o tseba ka ditaba tše difsa tša khamphani", filter_news: "Ditaba", filter_announcements: "Ditsebišo", filter_events: "Ditiragalo", filter_internal_notice: "Tsebišo ya ka gare", filter_anniversaries: "Mengwaga", filter_leaves: "Maikhutšo", no_content: "Ga go na diteng tše di lego gona gona bjale.", no_upcoming_events: "Ga go na ditiragalo tše di tlago gona bjale.", read_more: "Bala go feta", calendar: "Khalendara", upcoming_events: "Ditiragalo tše di tlago", no_upcoming_contents: "Ga go na diteng tše di tlago", company_event: "Tiragalo ya khamphani" },
    spotlights: { title: "Go bonagatša bašomi le dihlopha", subtitle: "Re keteka batho ba rena le dikatlego tša bona", view_details: "Lebelela dintlha", congratulate: "Lebogiša", close: "Tswalela" },
}

// Sesotho
const st: HomePage = {
    greeting: { good_morning: "Dumela hoseng", good_afternoon: "Dumela motšheare", good_evening: "Dumela mantsiboya", welcome_back: "Re a o amohela hape, {name}!" },
    hero: { featured: "Tse hlahelletseng", ceo_title: "Molaetsa o tswang ho CEO", ceo_fallback: "Dula o emetse molaetsa wa bohlokwa o tswang ho CEO wa rona.", read_more: "Bala ho eketsehileng", read_full_message: "Bala molaetsa o feletseng", video_tag: "Video" },
    quick_access: { title: "Phihlello e potlakileng", customize: "Fetola", add_shortcuts: "Tobetsa Fetola ho eketsa dikgutshwanyane", done: "Ho phethilwe" },
    top_resources: { title: "Disebediswa tse phahameng", subtitle: "Phihlello e potlakileng ho diforomo, ditemplate le melao", search_placeholder: "Batla diforomo le ditemplate...", show_more: "Bontsha disebediswa tse ding tse 6", show_less: "Bontsha tse fokolang" },
    news_events: { title: "Ditaba le Diketsahalo", subtitle: "Dula o tseba ka ditaba tse ncha tsa khamphani", filter_news: "Ditaba", filter_announcements: "Ditsebiso", filter_events: "Diketsahalo", filter_internal_notice: "Tsebiso ya kahare", filter_anniversaries: "Dilemo", filter_leaves: "Matsatsi a phomolo", no_content: "Ha ho na diteng tse teng hajwale.", no_upcoming_events: "Ha ho na diketsahalo tse tlang hajwale.", read_more: "Bala ho eketsehileng", calendar: "Kalendara", upcoming_events: "Diketsahalo tse tlang", no_upcoming_contents: "Ha ho na diteng tse tlang", company_event: "Ketsahalo ya khamphani" },
    spotlights: { title: "Ho hlahisa basebetsi le dihlopha", subtitle: "Re keteka batho ba rona le katleho ya bona", view_details: "Sheba dintlha", congratulate: "Lebohisa", close: "Kwala" },
}

// siSwati
const ss: HomePage = {
    greeting: { good_morning: "Sawubona ekuseni", good_afternoon: "Sawubona emini", good_evening: "Sawubona kusihlwa", welcome_back: "Wemukelekile futsi, {name}!" },
    hero: { featured: "Lokugcamile", ceo_title: "Umlayeto lophuma ku-CEO", ceo_fallback: "Hlala ulindzele umlayeto lobalulekile lophuma ku-CEO wetfu.", read_more: "Fundza kabanti", read_full_message: "Fundza umlayeto lophelele", video_tag: "Ividiyo" },
    quick_access: { title: "Kufinyelela lokusheshako", customize: "Lungisa", add_shortcuts: "Cindzetela Lungisa kute wengete tindlela letimfisha", done: "Kwentiwe" },
    top_resources: { title: "Tinsita letiphakeme", subtitle: "Kufinyelela lokusheshako kumafomu, ematemplate netinchubomgomo", search_placeholder: "Sesha emafomu nematemplate...", show_more: "Khombisa letinye tinsita leti-6", show_less: "Khombisa lokuncane" },
    news_events: { title: "Tindzaba neMicimbi", subtitle: "Hlala wati ngetindzaba letinsha tenkampani", filter_news: "Tindzaba", filter_announcements: "Timemetelo", filter_events: "Imicimbi", filter_internal_notice: "Satiso sangekhatsi", filter_anniversaries: "Iminyaka", filter_leaves: "Emalikhefu", no_content: "Akukho lokucuketfwe lokukhona njengamanje.", no_upcoming_events: "Akukho micimbi letako njengamanje.", read_more: "Fundza kabanti", calendar: "Ikhalenda", upcoming_events: "Imicimbi letako", no_upcoming_contents: "Akukho lokucuketfwe lokutako", company_event: "Umcimbi wenkampani" },
    spotlights: { title: "Kugcamisa batisebenti nemacembu", subtitle: "Sigubha bantfu betfu nemphumelelo yabo", view_details: "Buka imininingwane", congratulate: "Halalisela", close: "Vala" },
}

// Tshivenda
const ve: HomePage = {
    greeting: { good_morning: "Ndaa nga matsheloni", good_afternoon: "Ndaa nga masiari", good_evening: "Ndaa nga madekwana", welcome_back: "Ni tshou tanganedzwa hafhu, {name}!" },
    hero: { featured: "Zwo bvelelaho", ceo_title: "Mulaedza u bva kha CEO", ceo_fallback: "Dzulani no lindela mulaedza wa ndeme u bva kha CEO washu.", read_more: "Vhalani zwinzhi", read_full_message: "Vhalani mulaedza wo fhelelaho", video_tag: "Vidio" },
    quick_access: { title: "U swikelela nga u tavhanya", customize: "Shandukisani", add_shortcuts: "Puttshedzani Shandukisani u engedza zwipfufhi", done: "Zwo fhedzwa" },
    top_resources: { title: "Zwishumiswa zwa nṱha", subtitle: "U swikelela nga u tavhanya kha fomo, themplete na mbekanyamaitele", search_placeholder: "Todani fomo na themplete...", show_more: "Sumbedzani zwishumiswa zwiṅwe zwa 6", show_less: "Sumbedzani zwiṱuku" },
    news_events: { title: "Mafhungo na Zwiitea", subtitle: "Dzulani ni tshi divha nga mafhungo maswa a khamphani", filter_news: "Mafhungo", filter_announcements: "Zwidivhadzwa", filter_events: "Zwiitea", filter_internal_notice: "Ndivhadzo ya nga ngomu", filter_anniversaries: "Miṅwaha", filter_leaves: "Duvha ḽa u awela", no_content: "A hu na zwidzhiwa zwi re hone zwino.", no_upcoming_events: "A hu na zwiitea zwi ḓaho zwino.", read_more: "Vhalani zwinzhi", calendar: "Khalanda", upcoming_events: "Zwiitea zwi ḓaho", no_upcoming_contents: "A hu na zwidzhiwa zwi ḓaho", company_event: "Tshiitea tsha khamphani" },
    spotlights: { title: "U sumbedza vhashumi na zwigwada", subtitle: "Ri khou pembelela vhathu vhashu na kutshilele kwavho", view_details: "Sedzani zwidodombedzwa", congratulate: "Lumbedzani", close: "Valani" },
}

// Xitsonga
const ts: HomePage = {
    greeting: { good_morning: "Avuxeni", good_afternoon: "Inhlikanhi", good_evening: "Riperile", welcome_back: "Ma amukeriwa nakambe, {name}!" },
    hero: { featured: "Leswi humelelaka", ceo_title: "Rungula ro huma eka CEO", ceo_fallback: "Tshamani mi rindzela rungula ra nkoka ro huma eka CEO wa hina.", read_more: "Hlaya swo tala", read_full_message: "Hlaya rungula hinkwaro", video_tag: "Vhidiyo" },
    quick_access: { title: "Ku fikelela hi ku hatlisa", customize: "Cinca", add_shortcuts: "Tinyika Cinca ku engetela tindlela to koma", done: "Swi hetiwile" },
    top_resources: { title: "Switirhisiwa swo tlakuka", subtitle: "Ku fikelela hi ku hatlisa eka tifomo, titemplate na tinawu", search_placeholder: "Lava tifomo na titemplate...", show_more: "Kombisa switirhisiwa swin'wana swa 6", show_less: "Kombisa switsongo" },
    news_events: { title: "Mahungu na Swiendlakalo", subtitle: "Tshamani mi tiva hi mahungu lamantshwa ya khampani", filter_news: "Mahungu", filter_announcements: "Switiviso", filter_events: "Swiendlakalo", filter_internal_notice: "Xitiviso xa le ndzeni", filter_anniversaries: "Malembe", filter_leaves: "Ku wisa", no_content: "A ku na leswi nga kona sweswi.", no_upcoming_events: "A ku na swiendlakalo leswi taka sweswi.", read_more: "Hlaya swo tala", calendar: "Khalendara", upcoming_events: "Swiendlakalo leswi taka", no_upcoming_contents: "A ku na leswi taka", company_event: "Xiendlakalo xa khampani" },
    spotlights: { title: "Ku kombisa vatirhi na mintlawa", subtitle: "Hi tlangela vanhu va hina na ku humelela ka vona", view_details: "Languta vuxokoxoko", congratulate: "Khensa", close: "Pfala" },
}

// isiNdebele
const nr: HomePage = {
    greeting: { good_morning: "Lotjhani ekuseni", good_afternoon: "Lotjhani emini", good_evening: "Lotjhani ntambama", welcome_back: "Wamukelekile godu, {name}!" },
    hero: { featured: "Okuqakathekileko", ceo_title: "Umlayezo ovela ku-CEO", ceo_fallback: "Hlala ulindele umlayezo oqakathekileko ovela ku-CEO wethu.", read_more: "Funda okunengi", read_full_message: "Funda umlayezo opheleleko", video_tag: "Ividiyo" },
    quick_access: { title: "Ukufinyelela okurhabako", customize: "Lungisa", add_shortcuts: "Gandelela Lungisa ukungezelela iindlela ezifitjhani", done: "Kwenziwe" },
    top_resources: { title: "Iinsetjenziswa eziphezulu", subtitle: "Ukufinyelela okurhabako emafomini, ematemplete neenqubomgomo", search_placeholder: "Sesa amafomu nematemplete...", show_more: "Tjengisa ezinye iinsetjenziswa ezi-6", show_less: "Tjengisa okuncani" },
    news_events: { title: "Iindaba neminyanya", subtitle: "Hlala wazi ngeendaba ezitjha zekampani", filter_news: "Iindaba", filter_announcements: "Iimemezelo", filter_events: "Iminyanya", filter_internal_notice: "Isaziso sangaphakathi", filter_anniversaries: "Iminyaka", filter_leaves: "Amakhefu", no_content: "Akunalitho elikhona okwanjesi.", no_upcoming_events: "Akunayo iminyanya ezako okwanjesi.", read_more: "Funda okunengi", calendar: "Ikhalenda", upcoming_events: "Iminyanya ezako", no_upcoming_contents: "Akunalitho elizako", company_event: "Umnyanya wekampani" },
    spotlights: { title: "Ukuveza abasebenzi namaqembu", subtitle: "Sigidinga abantu bethu nempumelelo yabo", view_details: "Qala imininingwana", congratulate: "Halalisela", close: "Vala" },
}

export const dashboardHome: Record<string, HomePage> = { xh, nso, st, ss, ve, ts, nr }

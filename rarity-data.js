/* =========================================================================
   RZECZYWISTA RZADKOŚĆ SKINÓW (zgodna ze Steam Community Market) — WSPÓLNY PLIK
   -------------------------------------------------------------------------
   Klucz: "Broń|Skin" (bez wear — rzadkość nie zależy od zużycia).
   Wartość: nazwa zmiennej CSS koloru rzadkości (musi być zdefiniowana w
   :root danego pliku jako --r-milspec / --r-restricted / --r-classified /
   --r-covert / --r-gold).
   Źródło danych: ByMykel/CSGO-API (public/api/en/skins.json i
   public/api/en/stickers.json).

   Mapowanie prawdziwej rzadkości Steam na te 5 zmiennych CSS (Steam ma
   więcej stopni niż ta strona ma kolorów, więc kilka realnych rzadkości
   dzieli jedną zmienną):
     Consumer Grade, Industrial Grade, Mil-Spec Grade  -> --r-milspec (niebieski)
     Restricted, Remarkable (naklejki)                 -> --r-restricted (fiolet)
     Classified, Exotic (naklejki)                     -> --r-classified (róż)
     Covert, Extraordinary (noże/rękawice)             -> --r-covert (czerwony)
     Contraband                                        -> --r-gold (złoty)
   Noże i rękawice pokazują się w Steam jako ★ Extraordinary niezależnie od
   wykończenia (Doppler, Fade, Tiger Tooth...) - to osobna, kosmetyczna
   rzadkość pattern-a wewnątrz skrzynki, a nie tier wyświetlanego przedmiotu.
   ========================================================================= */

const SKIN_RARITY = {
  "AK-47|Aquamarine Revenge": "var(--r-covert)", // Covert
  "AK-47|B the Monster": "var(--r-covert)", // Covert
  "AK-47|Blue Laminate": "var(--r-restricted)", // Restricted
  "AK-47|Elite Build": "var(--r-milspec)", // Mil-Spec Grade
  "AK-47|Emerald Pinstripe": "var(--r-restricted)", // Restricted
  "AK-47|Frontside Misty": "var(--r-classified)", // Classified
  "AK-47|Gold Arabesque": "var(--r-covert)", // Covert
  "AK-47|Hydroponic": "var(--r-classified)", // Classified
  "AK-47|Inheritance": "var(--r-covert)", // Covert
  "AK-47|Jaguar": "var(--r-covert)", // Covert
  "AK-47|Neon Rider": "var(--r-covert)", // Covert
  "AK-47|Nouveau Rouge": "var(--r-classified)", // Classified
  "AK-47|Orbit Mk01": "var(--r-restricted)", // Restricted
  "AK-47|Point Disarray": "var(--r-classified)", // Classified
  "AK-47|Searing Rage": "var(--r-classified)", // Classified
  "AK-47|Wild Lotus": "var(--r-covert)", // Covert
  "AUG|Amber Slipstream": "var(--r-milspec)", // Mil-Spec Grade
  "AUG|Triqua": "var(--r-milspec)", // Mil-Spec Grade
  "AWP|Containment Breach": "var(--r-covert)", // Covert
  "AWP|Corticera": "var(--r-classified)", // Classified
  "AWP|Desert Hydra": "var(--r-covert)", // Covert
  "AWP|Dragon Lore": "var(--r-covert)", // Covert
  "AWP|Fade": "var(--r-covert)", // Covert
  "AWP|Green Energy": "var(--r-classified)", // Classified
  "AWP|Gungnir": "var(--r-covert)", // Covert
  "AWP|Oni Taiji": "var(--r-covert)", // Covert
  "AWP|Phobos": "var(--r-restricted)", // Restricted
  "AWP|Pink DDPAT": "var(--r-restricted)", // Restricted
  "AWP|Pit Viper": "var(--r-restricted)", // Restricted
  "AWP|Printstream": "var(--r-covert)", // Covert
  "AWP|The Prince": "var(--r-covert)", // Covert
  "AWP|Wildfire": "var(--r-covert)", // Covert
  "AWP|Worm God": "var(--r-restricted)", // Restricted
  "Bayonet|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Bayonet|Gamma Doppler Emerald": "var(--r-covert)", // Extraordinary
  "Bowie Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Bowie Knife|Gamma Doppler Emerald": "var(--r-covert)", // Extraordinary
  "Butterfly Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Butterfly Knife|Fade": "var(--r-covert)", // Covert
  "Butterfly Knife|Gamma Doppler Emerald": "var(--r-covert)", // Extraordinary
  "Butterfly Knife|Gamma Doppler Phase 2": "var(--r-covert)", // Extraordinary
  "Butterfly Knife|Lore": "var(--r-covert)", // Covert
  "CZ75-Auto|Emerald": "var(--r-milspec)", // Mil-Spec Grade
  "CZ75-Auto|Hexane": "var(--r-milspec)", // Mil-Spec Grade
  "CZ75-Auto|Pole Position": "var(--r-restricted)", // Restricted
  "CZ75-Auto|Tacticat": "var(--r-restricted)", // Restricted
  "CZ75-Auto|The Fuschia Is Now": "var(--r-classified)", // Classified
  "CZ75-Auto|Tigris": "var(--r-restricted)", // Restricted
  "CZ75-Auto|Victoria": "var(--r-covert)", // Covert
  "Classic Knife|Fade": "var(--r-covert)", // Covert
  "Desert Eagle|Blaze": "var(--r-restricted)", // Restricted
  "Desert Eagle|Crimson Web": "var(--r-restricted)", // Restricted
  "Desert Eagle|Golden Koi": "var(--r-covert)", // Covert
  "Desert Eagle|Heirloom": "var(--r-restricted)", // Restricted
  "Desert Eagle|Midnight Storm": "var(--r-milspec)", // Industrial Grade
  "Desert Eagle|Mint Fan": "var(--r-milspec)", // Mil-Spec Grade
  "Desert Eagle|Ocean Drive": "var(--r-covert)", // Covert
  "Desert Eagle|Oxide Blaze": "var(--r-milspec)", // Mil-Spec Grade
  "Desert Eagle|Printstream": "var(--r-covert)", // Covert
  "Desert Eagle|Sunset Storm 弐": "var(--r-restricted)", // Restricted
  "Desert Eagle|Urban Rubble": "var(--r-milspec)", // Mil-Spec Grade
  "Dual Berettas|Emerald": "var(--r-milspec)", // Mil-Spec Grade
  "Dual Berettas|Flora Carnivora": "var(--r-restricted)", // Restricted
  "FAMAS|Commemoration": "var(--r-covert)", // Covert
  "FAMAS|Eye of Athena": "var(--r-classified)", // Classified
  "FAMAS|Hexane": "var(--r-milspec)", // Mil-Spec Grade
  "FAMAS|Neural Net": "var(--r-restricted)", // Restricted
  "FAMAS|Styx": "var(--r-restricted)", // Restricted
  "FAMAS|Survivor Z": "var(--r-milspec)", // Mil-Spec Grade
  "FAMAS|Waters of Nephthys": "var(--r-classified)", // Classified
  "Falchion Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Falchion Knife|Gamma Doppler Emerald": "var(--r-covert)", // Extraordinary
  "Five-SeveN|Buddy": "var(--r-restricted)", // Restricted
  "Five-SeveN|Capillary": "var(--r-milspec)", // Mil-Spec Grade
  "Five-SeveN|Case Hardened": "var(--r-restricted)", // Restricted
  "Five-SeveN|Copper Galaxy": "var(--r-restricted)", // Restricted
  "Five-SeveN|Crimson Blossom": "var(--r-milspec)", // Mil-Spec Grade
  "Five-SeveN|Fairy Tale": "var(--r-classified)", // Classified
  "Five-SeveN|Fraise Crane": "var(--r-milspec)", // Mil-Spec Grade
  "Five-SeveN|Scumbria": "var(--r-milspec)", // Mil-Spec Grade
  "Five-SeveN|Urban Hazard": "var(--r-milspec)", // Mil-Spec Grade
  "Flip Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Flip Knife|Gamma Doppler Emerald": "var(--r-covert)", // Extraordinary
  "G3SG1|Dream Glade": "var(--r-restricted)", // Restricted
  "G3SG1|Orange Crash": "var(--r-milspec)", // Mil-Spec Grade
  "Galil AR|Aqua Terrace": "var(--r-milspec)", // Mil-Spec Grade
  "Galil AR|Galigator": "var(--r-restricted)", // Restricted
  "Galil AR|Phoenix Blacklight": "var(--r-restricted)", // Restricted
  "Galil AR|Rocket Pop": "var(--r-milspec)", // Mil-Spec Grade
  "Galil AR|Sandstorm": "var(--r-milspec)", // Mil-Spec Grade
  "Galil AR|Sky Mandala": "var(--r-milspec)", // Mil-Spec Grade
  "Galil AR|Stone Cold": "var(--r-restricted)", // Restricted
  "Glock-18|AXIA": "var(--r-classified)", // Classified
  "Glock-18|Brass": "var(--r-restricted)", // Restricted
  "Glock-18|Bunsen Burner": "var(--r-milspec)", // Mil-Spec Grade
  "Glock-18|Catacombs": "var(--r-milspec)", // Mil-Spec Grade
  "Glock-18|Fade": "var(--r-restricted)", // Restricted
  "Glock-18|Gamma Doppler Emerald": "var(--r-covert)", // Covert
  "Glock-18|Moonrise": "var(--r-restricted)", // Restricted
  "Glock-18|Ocean Topo": "var(--r-milspec)", // Industrial Grade
  "Glock-18|Off World": "var(--r-milspec)", // Mil-Spec Grade
  "Glock-18|Ramese's Reach": "var(--r-restricted)", // Restricted
  "Glock-18|Synth Leaf": "var(--r-restricted)", // Restricted
  "Glock-18|Vogue": "var(--r-classified)", // Classified
  "Glock-18|Warhawk": "var(--r-milspec)", // Mil-Spec Grade
  "Glock-18|Water Elemental": "var(--r-classified)", // Classified
  "Glock-18|Weasel": "var(--r-restricted)", // Restricted
  "Gut Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Gut Knife|Fade": "var(--r-covert)", // Covert
  "Gut Knife|Gamma Doppler Emerald": "var(--r-covert)", // Extraordinary
  "Gut Knife|Gamma Doppler Phase 1": "var(--r-covert)", // Extraordinary
  "Hand Wraps|CAUTION!": "var(--r-covert)", // Extraordinary
  "Hand Wraps|Cobalt Skulls": "var(--r-covert)", // Extraordinary
  "Hand Wraps|Desert Shamagh": "var(--r-covert)", // Extraordinary
  "Huntsman Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Huntsman Knife|Gamma Doppler Emerald": "var(--r-covert)", // Extraordinary
  "Karambit|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Karambit|Fade": "var(--r-covert)", // Covert
  "Karambit|Gamma Doppler Emerald": "var(--r-covert)", // Extraordinary
  "Karambit|Lore": "var(--r-covert)", // Covert
  "Karambit|Tiger Tooth": "var(--r-covert)", // Covert
  "M249|Emerald Poison Dart": "var(--r-restricted)", // Restricted
  "M249|System Lock": "var(--r-milspec)", // Mil-Spec Grade
  "M4A1-S|Basilisk": "var(--r-restricted)", // Restricted
  "M4A1-S|Black Lotus": "var(--r-classified)", // Classified
  "M4A1-S|Blood Tiger": "var(--r-milspec)", // Mil-Spec Grade
  "M4A1-S|Briefing": "var(--r-milspec)", // Mil-Spec Grade
  "M4A1-S|Bright Water": "var(--r-restricted)", // Restricted
  "M4A1-S|Chantico's Fire": "var(--r-covert)", // Covert
  "M4A1-S|Dark Water": "var(--r-restricted)", // Restricted
  "M4A1-S|Decimator": "var(--r-classified)", // Classified
  "M4A1-S|Fade": "var(--r-covert)", // Covert
  "M4A1-S|Flashback": "var(--r-restricted)", // Restricted
  "M4A1-S|Golden Coil": "var(--r-covert)", // Covert
  "M4A1-S|Icarus Fell": "var(--r-restricted)", // Restricted
  "M4A1-S|Master Piece": "var(--r-classified)", // Classified
  "M4A1-S|Nightmare": "var(--r-classified)", // Classified
  "M4A1-S|Nitro": "var(--r-restricted)", // Restricted
  "M4A1-S|Printstream": "var(--r-covert)", // Covert
  "M4A4|Evil Daimyo": "var(--r-restricted)", // Restricted
  "M4A4|Eye of Horus": "var(--r-covert)", // Covert
  "M4A4|Griffin": "var(--r-restricted)", // Restricted
  "M4A4|Hellfire": "var(--r-classified)", // Classified
  "M4A4|Hellish": "var(--r-classified)", // Classified
  "M4A4|Howl": "var(--r-gold)", // Contraband
  "M4A4|Royal Paladin": "var(--r-covert)", // Covert
  "M4A4|Temukau": "var(--r-covert)", // Covert
  "M4A4|The Emperor": "var(--r-covert)", // Covert
  "M9 Bayonet|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "M9 Bayonet|Fade": "var(--r-covert)", // Covert
  "M9 Bayonet|Gamma Doppler Emerald": "var(--r-covert)", // Extraordinary
  "M9 Bayonet|Lore": "var(--r-covert)", // Covert
  "MAC-10|Amber Fade": "var(--r-milspec)", // Mil-Spec Grade
  "MAC-10|Classic Crate": "var(--r-milspec)", // Mil-Spec Grade
  "MAC-10|Fade": "var(--r-milspec)", // Mil-Spec Grade
  "MAC-10|Gold Brick": "var(--r-restricted)", // Restricted
  "MAC-10|Oceanic": "var(--r-milspec)", // Mil-Spec Grade
  "MAC-10|Saibā Oni": "var(--r-restricted)", // Restricted
  "MAC-10|Toybox": "var(--r-classified)", // Classified
  "MAC-10|Ultraviolet": "var(--r-milspec)", // Mil-Spec Grade
  "MAG-7|Counter Terrace": "var(--r-milspec)", // Mil-Spec Grade
  "MAG-7|SWAG-7": "var(--r-restricted)", // Restricted
  "MAG-7|Sand Dune": "var(--r-milspec)", // Consumer Grade
  "MAG-7|Wildwood": "var(--r-milspec)", // Industrial Grade
  "MP5-SD|Co-Processor": "var(--r-milspec)", // Mil-Spec Grade
  "MP5-SD|Savannah Halftone": "var(--r-milspec)", // Industrial Grade
  "MP7|Akoben": "var(--r-milspec)", // Mil-Spec Grade
  "MP7|Amberline": "var(--r-restricted)", // Restricted
  "MP7|Cirrus": "var(--r-milspec)", // Mil-Spec Grade
  "MP7|Nemesis": "var(--r-classified)", // Classified
  "MP7|Ocean Foam": "var(--r-restricted)", // Restricted
  "MP7|Special Delivery": "var(--r-restricted)", // Restricted
  "MP7|Urban Hazard": "var(--r-milspec)", // Mil-Spec Grade
  "MP9|Black Sand": "var(--r-milspec)", // Mil-Spec Grade
  "MP9|Mount Fuji": "var(--r-restricted)", // Restricted
  "MP9|Ruby Poison Dart": "var(--r-restricted)", // Restricted
  "MP9|Sand Dashed": "var(--r-milspec)", // Consumer Grade
  "MP9|Setting Sun": "var(--r-milspec)", // Mil-Spec Grade
  "MP9|Wild Lily": "var(--r-classified)", // Classified
  "Moto Gloves|Spearmint": "var(--r-covert)", // Extraordinary
  "Navaja Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Negev|Loudmouth": "var(--r-restricted)", // Restricted
  "Negev|Mjölnir": "var(--r-classified)", // Classified
  "Negev|Power Loader": "var(--r-restricted)", // Restricted
  "Nomad Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "P2000|Fire Elemental": "var(--r-covert)", // Covert
  "P2000|Ocean Foam": "var(--r-classified)", // Classified
  "P2000|Oceanic": "var(--r-milspec)", // Mil-Spec Grade
  "P2000|Royal Baroque": "var(--r-milspec)", // Mil-Spec Grade
  "P250|Apep's Curse": "var(--r-classified)", // Classified
  "P250|Sand Dune": "var(--r-milspec)", // Consumer Grade
  "P250|Supernova": "var(--r-restricted)", // Restricted
  "P250|Valence": "var(--r-milspec)", // Mil-Spec Grade
  "P250|Vino Primo": "var(--r-restricted)", // Restricted
  "P250|Wingshot": "var(--r-restricted)", // Restricted
  "P90|Astral Jörmungandr": "var(--r-restricted)", // Restricted
  "P90|Chopper": "var(--r-restricted)", // Restricted
  "P90|Elite Build": "var(--r-milspec)", // Mil-Spec Grade
  "P90|Facility Negative": "var(--r-milspec)", // Mil-Spec Grade
  "P90|Grim": "var(--r-milspec)", // Mil-Spec Grade
  "P90|ScaraB Rush": "var(--r-restricted)", // Restricted
  "PP-Bizon|Antique": "var(--r-restricted)", // Restricted
  "PP-Bizon|Jungle Slipstream": "var(--r-milspec)", // Mil-Spec Grade
  "Paracord Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "R8 Revolver|Crimson Web": "var(--r-milspec)", // Mil-Spec Grade
  "SCAR-20|Blueprint": "var(--r-milspec)", // Mil-Spec Grade
  "SG 553|Aerial": "var(--r-milspec)", // Mil-Spec Grade
  "SG 553|Colony IV": "var(--r-classified)", // Classified
  "SG 553|Triarch": "var(--r-restricted)", // Restricted
  "SSG 08|Abyss": "var(--r-milspec)", // Mil-Spec Grade
  "SSG 08|Big Iron": "var(--r-classified)", // Classified
  "SSG 08|Dragonfire": "var(--r-covert)", // Covert
  "SSG 08|Ghost Crusader": "var(--r-restricted)", // Restricted
  "SSG 08|Slashed": "var(--r-milspec)", // Mil-Spec Grade
  "Sawed-Off|Kiss♥Love": "var(--r-classified)", // Classified
  "Sawed-Off|The Kraken": "var(--r-covert)", // Covert
  "Shadow Daggers|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Shadow Daggers|Gamma Doppler Emerald": "var(--r-covert)", // Extraordinary
  "Skeleton Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Skeleton Knife|Slaughter": "var(--r-covert)", // Covert
  "Sport Gloves|Slingshot": "var(--r-covert)", // Extraordinary
  "Sport Gloves|Vice": "var(--r-covert)", // Extraordinary
  "Sport Gloves|Violet Beadwork": "var(--r-covert)", // Extraordinary
  "Sticker|Blue Gem (Glitter)": "var(--r-restricted)", // Remarkable
  "Sticker|Cheongsam (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Clavis (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Doppler Poison Frog (Foil)": "var(--r-classified)", // Exotic
  "Sticker|Get Smoked (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Gold Web": "var(--r-milspec)", // High Grade
  "Sticker|Kawaii CT (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Kawaii T (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Liquid Fire (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Lorena (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Lotus (Glitter)": "var(--r-restricted)", // Remarkable
  "Sticker|Loving Eyes (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Merietta (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Nelu the Bear": "var(--r-milspec)", // High Grade
  "Sticker|Rare Atom (Holo) | Shanghai 2024": "var(--r-classified)", // Exotic
  "Sticker|Ribbon Tie": "var(--r-milspec)", // High Grade
  "Sticker|Sherry (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Toxic Flow (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Unicorn (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|V For Victory (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|Vice Cursor": "var(--r-milspec)", // High Grade
  "Sticker|Wildcard (Gold) | Shanghai 2024": "var(--r-covert)", // Extraordinary
  "Sticker|Zeusception (Holo)": "var(--r-restricted)", // Remarkable
  "Sticker|rox (Holo) | Antwerp 2022": "var(--r-classified)", // Exotic
  "Stiletto Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Survival Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Survival Knife|Tiger Tooth": "var(--r-covert)", // Covert
  "Talon Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Talon Knife|Doppler Ruby": "var(--r-covert)", // Extraordinary
  "Tec-9|Fubar": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Fuel Injector": "var(--r-classified)", // Classified
  "Tec-9|Ice Cap": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Isaac": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Jambiya": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Mummy's Rot": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Sandstorm": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Terrace": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Toxic": "var(--r-milspec)", // Mil-Spec Grade
  "UMP-45|Arctic Wolf": "var(--r-restricted)", // Restricted
  "UMP-45|Briefing": "var(--r-milspec)", // Mil-Spec Grade
  "UMP-45|Exposure": "var(--r-restricted)", // Restricted
  "UMP-45|Labyrinth": "var(--r-milspec)", // Mil-Spec Grade
  "USP-S|Ancient Visions": "var(--r-restricted)", // Restricted
  "USP-S|Check Engine": "var(--r-milspec)", // Mil-Spec Grade
  "USP-S|Cyrex": "var(--r-restricted)", // Restricted
  "USP-S|Guardian": "var(--r-restricted)", // Restricted
  "USP-S|Jawbreaker": "var(--r-classified)", // Classified
  "USP-S|Kill Confirmed": "var(--r-covert)", // Covert
  "USP-S|Orange Anolis": "var(--r-restricted)", // Restricted
  "USP-S|Overgrowth": "var(--r-restricted)", // Restricted
  "USP-S|Printstream": "var(--r-covert)", // Covert
  "USP-S|Purple DDPAT": "var(--r-milspec)", // Mil-Spec Grade
  "USP-S|Royal Guard": "var(--r-restricted)", // Restricted
  "USP-S|Torque": "var(--r-milspec)", // Mil-Spec Grade
  "USP-S|Tropical Breeze": "var(--r-milspec)", // Mil-Spec Grade
  "Ursus Knife|Doppler Black Pearl": "var(--r-covert)", // Extraordinary
  "Zeus x27|Earth Mandala": "var(--r-milspec)", // Mil-Spec Grade
  "Zeus x27|Olympus": "var(--r-classified)", // Classified
};

/* Bronie białe i rękawice ZAWSZE pokazują się jako ★ Extraordinary (--r-covert)
   niezależnie od konkretnego wykończenia - to jedyny słuszny fallback, gdyby
   dla nowo dodanego skina zabrakło jawnego wpisu wyżej (np. świeżo dodana
   skrzynka). Bez tego nowe noże/rękawice wpadałyby w błędny, oparty na cenie
   fallback niżej w getSkinRarityColor() wywołujących plikach. */
const KNIFE_GLOVE_WEAPONS = new Set([
  "Bayonet", "Bowie Knife", "Butterfly Knife", "Classic Knife", "Falchion Knife",
  "Flip Knife", "Gut Knife", "Huntsman Knife", "Karambit", "M9 Bayonet",
  "Navaja Knife", "Nomad Knife", "Paracord Knife", "Shadow Daggers", "Skeleton Knife",
  "Stiletto Knife", "Survival Knife", "Talon Knife", "Ursus Knife",
  "Hand Wraps", "Moto Gloves", "Sport Gloves",
]);

/* Zwraca kolor rzadkości (CSS var) dla danego przedmiotu na podstawie broni
   i skina. Zwraca null, jeśli skin nie jest w bazie ani nie jest nożem/
   rękawicami (wywołujący powinien wtedy użyć własnego fallbacku, np.
   opartego na cenie). */
function getSkinRarityColor(weapon, skin){
  const known = SKIN_RARITY[`${weapon}|${skin}`];
  if(known) return known;
  if(KNIFE_GLOVE_WEAPONS.has(weapon)) return "var(--r-covert)";
  return null;
}

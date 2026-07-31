/* =========================================================================
   RZECZYWISTA RZADKOŚĆ SKINÓW (zgodna ze Steam Community Market) — WSPÓLNY PLIK
   -------------------------------------------------------------------------
   Klucz: "Broń|Skin" (bez wear — rzadkość nie zależy od zużycia).
   Wartość: nazwa zmiennej CSS koloru rzadkości (musi być zdefiniowana w
   :root danego pliku jako --r-milspec / --r-restricted / --r-classified /
   --r-covert / --r-gold).
   Źródło danych: ByMykel/CSGO-API (public/api/en/skins.json).
   ========================================================================= */

const SKIN_RARITY = {
  "AK-47|Blue Laminate": "var(--r-restricted)", // Restricted
  "AK-47|Elite Build": "var(--r-milspec)", // Mil-Spec Grade
  "AK-47|Emerald Pinstripe": "var(--r-restricted)", // Restricted
  "AK-47|Frontside Misty": "var(--r-classified)", // Classified
  "AK-47|Orbit Mk01": "var(--r-restricted)", // Restricted
  "AK-47|Point Disarray": "var(--r-classified)", // Classified
  "AUG|Triqua": "var(--r-milspec)", // Mil-Spec Grade
  "AWP|Phobos": "var(--r-restricted)", // Restricted
  "AWP|Pink DDPAT": "var(--r-restricted)", // Restricted
  "AWP|Pit Viper": "var(--r-restricted)", // Restricted
  "AWP|Printstream": "var(--r-covert)", // Covert
  "AWP|Worm God": "var(--r-restricted)", // Restricted
  "CZ75-Auto|Hexane": "var(--r-milspec)", // Mil-Spec Grade
  "CZ75-Auto|Pole Position": "var(--r-restricted)", // Restricted
  "CZ75-Auto|Tacticat": "var(--r-restricted)", // Restricted
  "CZ75-Auto|Tigris": "var(--r-restricted)", // Restricted
  "Desert Eagle|Blaze": "var(--r-restricted)", // Restricted
  "Desert Eagle|Crimson Web": "var(--r-restricted)", // Restricted
  "Desert Eagle|Midnight Storm": "var(--r-milspec)", // Industrial Grade
  "Desert Eagle|Ocean Drive": "var(--r-covert)", // Covert
  "Desert Eagle|Oxide Blaze": "var(--r-milspec)", // Mil-Spec Grade
  "Desert Eagle|Printstream": "var(--r-covert)", // Covert
  "Desert Eagle|Sunset Storm 弐": "var(--r-restricted)", // Restricted
  "Desert Eagle|Urban Rubble": "var(--r-milspec)", // Mil-Spec Grade
  "FAMAS|Hexane": "var(--r-milspec)", // Mil-Spec Grade
  "FAMAS|Styx": "var(--r-restricted)", // Restricted
  "FAMAS|Survivor Z": "var(--r-milspec)", // Mil-Spec Grade
  "FAMAS|Waters of Nephthys": "var(--r-classified)", // Classified
  "Five-SeveN|Capillary": "var(--r-milspec)", // Mil-Spec Grade
  "Five-SeveN|Case Hardened": "var(--r-restricted)", // Restricted
  "Five-SeveN|Crimson Blossom": "var(--r-milspec)", // Mil-Spec Grade
  "Five-SeveN|Scumbria": "var(--r-milspec)", // Mil-Spec Grade
  "Five-SeveN|Urban Hazard": "var(--r-milspec)", // Mil-Spec Grade
  "G3SG1|Orange Crash": "var(--r-milspec)", // Mil-Spec Grade
  "Galil AR|Aqua Terrace": "var(--r-milspec)", // Mil-Spec Grade
  "Galil AR|Rocket Pop": "var(--r-milspec)", // Mil-Spec Grade
  "Galil AR|Sandstorm": "var(--r-milspec)", // Mil-Spec Grade
  "Galil AR|Stone Cold": "var(--r-restricted)", // Restricted
  "Glock-18|Bunsen Burner": "var(--r-milspec)", // Mil-Spec Grade
  "Glock-18|Catacombs": "var(--r-milspec)", // Mil-Spec Grade
  "Glock-18|Fade": "var(--r-restricted)", // Restricted
  "Glock-18|Moonrise": "var(--r-restricted)", // Restricted
  "Glock-18|Ocean Topo": "var(--r-milspec)", // Industrial Grade
  "Glock-18|Off World": "var(--r-milspec)", // Mil-Spec Grade
  "Glock-18|Warhawk": "var(--r-milspec)", // Mil-Spec Grade
  "Glock-18|Water Elemental": "var(--r-classified)", // Classified
  "Glock-18|Weasel": "var(--r-restricted)", // Restricted
  "Gut Knife|Gamma Doppler Phase 1": "var(--r-covert)", // Covert
  "Hand Wraps|CAUTION!": "var(--r-covert)", // Extraordinary
  "Hand Wraps|Cobalt Skulls": "var(--r-covert)", // Extraordinary
  "Karambit|Tiger Tooth": "var(--r-covert)", // Covert
  "M249|System Lock": "var(--r-milspec)", // Mil-Spec Grade
  "M4A1-S|Basilisk": "var(--r-restricted)", // Restricted
  "M4A1-S|Blood Tiger": "var(--r-milspec)", // Mil-Spec Grade
  "M4A1-S|Briefing": "var(--r-milspec)", // Mil-Spec Grade
  "M4A1-S|Bright Water": "var(--r-restricted)", // Restricted
  "M4A1-S|Dark Water": "var(--r-restricted)", // Restricted
  "M4A1-S|Flashback": "var(--r-restricted)", // Restricted
  "M4A1-S|Icarus Fell": "var(--r-restricted)", // Restricted
  "M4A1-S|Master Piece": "var(--r-classified)", // Classified
  "M4A1-S|Nightmare": "var(--r-classified)", // Classified
  "M4A1-S|Nitro": "var(--r-restricted)", // Restricted
  "M4A1-S|Printstream": "var(--r-covert)", // Covert
  "M4A4|Evil Daimyo": "var(--r-restricted)", // Restricted
  "M4A4|Griffin": "var(--r-restricted)", // Restricted
  "MAC-10|Amber Fade": "var(--r-milspec)", // Mil-Spec Grade
  "MAC-10|Classic Crate": "var(--r-milspec)", // Mil-Spec Grade
  "MAC-10|Fade": "var(--r-milspec)", // Mil-Spec Grade
  "MAC-10|Oceanic": "var(--r-milspec)", // Mil-Spec Grade
  "MAC-10|Toybox": "var(--r-classified)", // Classified
  "MAC-10|Ultraviolet": "var(--r-milspec)", // Mil-Spec Grade
  "MAG-7|Counter Terrace": "var(--r-milspec)", // Mil-Spec Grade
  "MAG-7|SWAG-7": "var(--r-restricted)", // Restricted
  "MP5-SD|Co-Processor": "var(--r-milspec)", // Mil-Spec Grade
  "MP7|Akoben": "var(--r-milspec)", // Mil-Spec Grade
  "MP7|Cirrus": "var(--r-milspec)", // Mil-Spec Grade
  "MP7|Urban Hazard": "var(--r-milspec)", // Mil-Spec Grade
  "MP9|Setting Sun": "var(--r-milspec)", // Mil-Spec Grade
  "Negev|Loudmouth": "var(--r-restricted)", // Restricted
  "Negev|Power Loader": "var(--r-restricted)", // Restricted
  "P2000|Oceanic": "var(--r-milspec)", // Mil-Spec Grade
  "P250|Supernova": "var(--r-restricted)", // Restricted
  "P250|Valence": "var(--r-milspec)", // Mil-Spec Grade
  "P250|Wingshot": "var(--r-restricted)", // Restricted
  "P90|Chopper": "var(--r-restricted)", // Restricted
  "P90|Elite Build": "var(--r-milspec)", // Mil-Spec Grade
  "P90|Facility Negative": "var(--r-milspec)", // Mil-Spec Grade
  "P90|Grim": "var(--r-milspec)", // Mil-Spec Grade
  "P90|ScaraB Rush": "var(--r-restricted)", // Restricted
  "PP-Bizon|Jungle Slipstream": "var(--r-milspec)", // Mil-Spec Grade
  "R8 Revolver|Crimson Web": "var(--r-milspec)", // Mil-Spec Grade
  "SCAR-20|Blueprint": "var(--r-milspec)", // Mil-Spec Grade
  "SG 553|Aerial": "var(--r-milspec)", // Mil-Spec Grade
  "SSG 08|Abyss": "var(--r-milspec)", // Mil-Spec Grade
  "SSG 08|Ghost Crusader": "var(--r-restricted)", // Restricted
  "SSG 08|Slashed": "var(--r-milspec)", // Mil-Spec Grade
  "Sawed-Off|The Kraken": "var(--r-covert)", // Covert
  "Sticker|Nelu the Bear": "var(--r-milspec)", // High Grade
  "Tec-9|Fubar": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Fuel Injector": "var(--r-classified)", // Classified
  "Tec-9|Ice Cap": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Isaac": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Jambiya": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Sandstorm": "var(--r-milspec)", // Mil-Spec Grade
  "Tec-9|Toxic": "var(--r-milspec)", // Mil-Spec Grade
  "UMP-45|Arctic Wolf": "var(--r-restricted)", // Restricted
  "UMP-45|Briefing": "var(--r-milspec)", // Mil-Spec Grade
  "UMP-45|Exposure": "var(--r-restricted)", // Restricted
  "UMP-45|Labyrinth": "var(--r-milspec)", // Mil-Spec Grade
  "USP-S|Check Engine": "var(--r-milspec)", // Mil-Spec Grade
  "USP-S|Cyrex": "var(--r-restricted)", // Restricted
  "USP-S|Guardian": "var(--r-restricted)", // Restricted
  "USP-S|Jawbreaker": "var(--r-classified)", // Classified
  "USP-S|Overgrowth": "var(--r-restricted)", // Restricted
  "USP-S|Printstream": "var(--r-covert)", // Covert
  "USP-S|Torque": "var(--r-milspec)", // Mil-Spec Grade
  "Zeus x27|Olympus": "var(--r-classified)", // Classified
};

/* Zwraca kolor rzadkości (CSS var) dla danego przedmiotu na podstawie broni
   i skina. Zwraca null, jeśli skin nie jest w bazie (wywołujący powinien
   wtedy użyć własnego fallbacku). */
function getSkinRarityColor(weapon, skin){
  return SKIN_RARITY[`${weapon}|${skin}`] || null;
}

-- Schemat MySQL 8 dla wersji PHP. Zaimportuj przez phpMyAdmin (albo
-- `mysql -u user -p dbname < schema.sql`) na hostingu ZANIM wgrasz pliki PHP.

CREATE TABLE IF NOT EXISTS users (
  steamid VARCHAR(32) NOT NULL PRIMARY KEY,
  display_name VARCHAR(191) NOT NULL,
  avatar VARCHAR(512) NULL,
  profile_url VARCHAR(512) NULL,
  slug VARCHAR(40) NULL UNIQUE,
  created_at BIGINT NOT NULL,
  -- Osobna kolumna (nie w `state`) - admin przełącza ją niezależnie od
  -- reszty stanu gracza, a `state` jest w całości nadpisywane przy każdym
  -- zwykłym zapisie z gry (patrz api/state.php), więc trzymana tam flaga
  -- przetrwałaby tylko do najbliższej synchronizacji.
  boosted_drop TINYINT(1) NOT NULL DEFAULT 0,
  -- Cały obiekt stanu gry (balance, inventory, level, xp, questClaims,
  -- battleHistory, itd.) - jeden JSON, mapuje 1:1 dawny `u.state` z Node.
  state JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drobne dane serwisowe niezwiązane z żadnym konkretnym graczem (data
-- ostatniej wypłaty "Niefartu dnia" itp.) - odpowiednik meta.json z Node.
CREATE TABLE IF NOT EXISTS app_meta (
  meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
  meta_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Codzienne najlepsze bitwy" - TOP wpisów per dzień (klucz = data
-- YYYY-MM-DD czasu polskiego). Jeden wiersz na dzień, cała lista jako JSON,
-- tak samo jak klucz Redis cs2sim:dailytop:<data> w battle-lobby.js.
CREATE TABLE IF NOT EXISTS daily_top (
  date_str VARCHAR(10) NOT NULL PRIMARY KEY,
  entries JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Case Battle - cały stan lobby (sloty, rundy, wyniki) jako JSON, tak jak
-- obiekt `lobby` w battle-lobby.js. W Node żył w pamięci procesu; tu MUSI
-- żyć w bazie, bo każdy request PHP jest bezstanowy.
CREATE TABLE IF NOT EXISTS battle_lobbies (
  id VARCHAR(40) NOT NULL PRIMARY KEY,
  status VARCHAR(16) NOT NULL,
  is_private TINYINT(1) NOT NULL DEFAULT 0,
  data JSON NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  INDEX idx_status (status),
  INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

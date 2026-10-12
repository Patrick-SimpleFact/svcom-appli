-- Exécuté une seule fois, à la création du volume de la base de production.
-- Extensions utilisées par Spettacoli (SCHEMA-BDD.md) : positions, recherche tolérante, sans accents.
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;

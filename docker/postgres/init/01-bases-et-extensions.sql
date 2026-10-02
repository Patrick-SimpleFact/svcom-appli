-- Exécuté une seule fois, à la création du volume de données.
-- Base de développement (spettacoli, créée par l'image) + base des tests automatiques.
CREATE DATABASE spettacoli_test OWNER spettacoli;

-- Extensions utilisées par Spettacoli (SCHEMA-BDD.md) :
--   postgis  : positions et distances (« autour de moi »)
--   pg_trgm  : recherche tolérante aux fautes de frappe
--   unaccent : recherche insensible aux accents
\connect spettacoli
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;

\connect spettacoli_test
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;

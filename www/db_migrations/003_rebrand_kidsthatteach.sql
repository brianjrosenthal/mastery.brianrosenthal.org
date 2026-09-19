-- Rebrand: the main site moves from mastery.brianrosenthal.org ("Mastery") to
-- kidsthatteach.org ("Kids That Teach"). Only the seeded defaults are touched;
-- a title or URL an admin already customised is left alone.
UPDATE settings SET value = 'Kids That Teach'
  WHERE key_name = 'site_title' AND value = 'Mastery';
UPDATE settings SET value = 'https://kidsthatteach.org'
  WHERE key_name = 'site_base_url' AND value = 'https://mastery.brianrosenthal.org';

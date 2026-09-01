HOW TO APPLY THESE FIXES
=========================

This turns out to be a known limitation right now: Cowork sessions have no
way to be granted push access to a GitHub repo, from either side — so I
can't open the PR myself. Two ways to get these changes live:

OPTION A — upload directly via cPanel (matches how this site is already deployed)
-----------------------------------------------------------------------------
1. In cPanel File Manager, go to public_html and upload/overwrite these
   files with the ones in this folder, keeping the same paths:
     .htaccess                 (this REPLACES the old "htaccess" — see step 2)
     admin/auth.php
     admin/event-delete.php
     admin/event-edit.php
     admin/project-action.php
     admin/settings.php
     admin/smtp-config.php
     api/events.php
     api/projects.php
     api/transparency.php
     join-submit.php
     .gitignore                (harmless if cPanel doesn't use git; safe to upload anyway)
     config.sample.php

2. Delete the old "htaccess" file (no leading dot) once ".htaccess" is
   uploaded — having both would be confusing, and only ".htaccess" is
   actually read by Apache.

3. Delete these two files — they're server logs that were accidentally
   committed and publicly exposed; they don't do anything and shouldn't
   exist on the live site or in the repo:
     admin/error_log (1)
     api/error_log

4. Create a NEW file at public_html/config.php (copy config.sample.php and
   fill in the real values):
     DB_HOST, DB_NAME, DB_USER, DB_PASS   — from cPanel > MySQL Databases
     SMTP_HOST, SMTP_PORT, SMTP_SECURE, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME
   Use the NEW, ROTATED passwords here — not the old ones that were public.
   Recommended permissions: chmod 600 config.php.

5. Rotate the passwords themselves if you haven't already:
     - cPanel > MySQL Databases: change the password for dzppntag_eventmaster
     - The mailbox: change the password for office@foreningenfrontdoor.dk
   Do this BEFORE or right after step 4 — the site won't connect to the
   database or send email until config.php has the matching new password.

6. Also update the GitHub repo itself with these same file changes (via the
   web UI, editing each file, or from your own machine with `git`) so the
   public repo isn't still showing the old vulnerable code — the .patch
   file included here applies cleanly with `git apply` or `git am` if you
   have this repo cloned locally with your own push access.


OPTION B — apply the patch from your own machine
--------------------------------------------------------
If you have this repo cloned locally (with your own GitHub credentials):
  cd foreningenfrontdoor.dk
  git checkout -b security/fix-review-findings
  git am /path/to/0001-fix-security-review-findings.patch
  git push -u origin security/fix-review-findings
Then open the PR on GitHub, or push straight to main if you don't need review.
This also handles the file deletions and the htaccess rename automatically.
Then do steps 4-5 above (create config.php on the server, rotate the passwords)
since git alone doesn't touch your live server.

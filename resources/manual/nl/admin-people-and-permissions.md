# Mensen en rechten

Wie kan inloggen, en wat elk van hen mag.

## Rollen dragen rechten, mensen dragen rollen

Niemand krijgt rechtstreeks een recht. Een **rol** is een benoemde bundel rechten — "Agent", "Teamleider", "Alleen lezen" — en mensen krijgen rollen. Verander wat een rol mag en iedereen met die rol verandert mee.

Een rol heeft een **bereik**, en dat bereik bepaalt op welke kant van Ticktz de houders terechtkomen. Een rol met agent- of beheerbereik maakt iemand personeel; een rol met melderbereik maakt iemand klant.

De rechtenlijst zelf ligt vast — hij staat in de code, niet in de interface — dus een recht kan niet per typefout ontstaan, en elk recht erin is iets dat de applicatie daadwerkelijk controleert.

## Iemand toevoegen

**Beheer → Gebruikers → Nieuwe gebruiker.** Wat bewuste aandacht verdient:

- **Rollen.** Dit is alles wat ze mogen. Geef de smalste rol waarmee ze kunnen werken.
- **Organisatie**, voor een klant. Dat maakt "iedereen bij Acme" een betekenisvolle groep, en bepaalt of collega's elkaars verzoeken zien.
- **Leidinggevende.** Gebruikt door fiatteringsstappen die om "de leidinggevende van de melder" vragen. Zonder die heeft zo'n stap niemand om te vragen.

Wie via je directory inlogt wordt bij de eerste aanmelding aangemaakt; die voeg je niet met de hand toe.

## Deactiveren in plaats van verwijderen

Deactiveer wie vertrokken is. Dat blokkeert inloggen en haalt de persoon uit de kiezers, en houdt elk ticket, elke reactie en elke beslissing intact en op naam.

Iemand verwijderen die tickets heeft behandeld zou een desk opleveren vol werk dat niemand gedaan lijkt te hebben.

## Teams

Een team is een groep agents die wachtrijen en tickets bezit. Teams zijn hoe werk wordt verdeeld, en ze zijn op één punt dragend: **een ticket dat bij een team hoort, kan alleen worden toegewezen aan iemand uit dat team.**

Dat is het waard te weten vóórdat je ze inricht. Een te smal team levert tickets op die niemand kan krijgen.

**Leiders** zijn leden met een vlag. Escalaties en rapportages gebruiken die.

## Organisaties

Een organisatie groepeert klanten — een bedrijf, een afdeling, een locatie.

De ene instelling die gedrag verandert is **gedeelde ticketzichtbaarheid**. Zet die aan en collega's binnen dezelfde organisatie zien elkaars verzoeken in het portaal; laat hem uit en iedereen ziet alleen zijn eigen. Dat is de juiste instelling voor een gedeelde postbus en de verkeerde voor een HR-desk.

## Inloggen via directory

Een directory koppelen betekent dat mensen hun bestaande wachtwoord gebruiken en dat jij geen tweede lijst meer bijhoudt. Ticktz leest accounts en groepen; het schrijft niets naar je directory.

Test de verbinding voordat je erop vertrouwt. Een directory die wel authenticeert maar geen groepen teruggeeft, levert mensen op die kunnen inloggen en niets kunnen.

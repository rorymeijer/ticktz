# Automatisering

Regels die op tickets ingrijpen zonder dat iemand erom vraagt. Een regel is een aanleiding, een aantal voorwaarden en een aantal acties: *wanneer* dit gebeurt, *als* dit waar is, *doe* dan dit.

## Aanleidingen, voorwaarden, acties

Een **aanleiding** is het moment waarop een regel wordt overwogen — een ticket aangemaakt, gewijzigd, becommentarieerd, of een geplande ronde.

**Voorwaarden** perken het in. Veldvergelijkingen — prioriteit is hoog, wachtrij is Hardware, behandelaar is niemand — gecombineerd met alles of één daarvan.

**Acties** zijn wat er gebeurt: aan iemand toewijzen, naar een team verplaatsen, een prioriteit zetten, een label toevoegen, een overgang maken, een volger toevoegen, een webhook versturen.

## Regels om regels mee te schrijven

**Begin bij de voorwaarden.** Een regel zonder voorwaarden draait op alles, en je komt erachter doordat het audit-spoor ermee volloopt.

**Maak de eerste smal genoeg om te volgen.** Elke uitvoering wordt vastgelegd met wat hij deed en of het lukte. Volg een nieuwe regel een dag voordat je hem verbreedt.

**Volgorde telt.** Regels draaien op volgorde en zien wat eerdere deden. Twee regels die allebei de prioriteit zetten draaien allebei; de laatste wint.

**Toewijzen volgt de teamregel.** Een regel die iemand toewijst die niet in het team van het ticket zit, doet dat niet, en legt vast waarom. Dat is met opzet: een regel die de regel kon omzeilen zou de weg eromheen zijn, en de stilste — het gebeurt om drie uur 's nachts en het spoor zegt dat geen mens het deed.

## Ze in de gaten houden

De uitvoeringslijst toont wat er draaide, op welk ticket, en wat elke actie deed. Een actie die niets kon uitrichten zegt dat, in plaats van succes te claimen — "geen actieve gebruiker", "niet in het team van dit ticket", "niet toegestaan door de workflow".

Een regel die nooit afgaat en een regel die afgaat en faalt zien er hier volstrekt anders uit, en dat is de bedoeling.

## Webhooks

Een webhook post naar een URL die jij beheert als er iets gebeurt, en zo vertelt Ticktz een ander systeem over een ticket.

De URL moet https zijn. Voeg een geheim toe en de inhoud wordt ondertekend, zodat de ontvangende kant jouw Ticktz kan onderscheiden van ieder ander die het adres vond.

Mislukkingen worden opnieuw geprobeerd. Een URL die tien minuten plat ligt verliest de gebeurtenis niet.

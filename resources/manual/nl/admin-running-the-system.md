# Het systeem draaiende houden

## Instellingen

De naam, het logo en de kleuren waarin het portaal en de meldingen verschijnen, plus de standaardwaarden die een nieuw ticket krijgt als niets anders beslist.

Het meeste stel je één keer in. Wat het waard is opnieuw te bekijken zijn de standaardwachtrij en het standaardverzoektype: daar komt terecht wat nergens op matchte, en een desk die gegroeid is, is meestal zijn eerste antwoord ontgroeid.

## Updates

**Beheer → Updates** kijkt of er een nieuwere Ticktz is, en installeert er — op een installatie die dat toestaat — één.

Voordat je erop drukt:

- **Maak een back-up.** `scripts/backup.sh` staat in de repository. Een upgrade draait migraties, en migraties zijn wat je niet ongedaan maakt door de oude code terug te zetten.
- **Lees de releasenotities.** Een minor versie vraagt nooit om een handmatige stap en een patch verandert nooit de database, dus waar je naar zoekt is of dit een major is.

Het updatescherm controleert vooraf wat het kan — of de code beschrijfbaar is, of de database bereikbaar is, of er ruimte is voor de nieuwe versie naast de oude — en weigert in plaats van half te installeren. Weigert het, dan is de gegeven reden het ding om te verhelpen.

De vorige versie blijft bewaard, dus een mislukte migratie heeft ergens om naar terug te keren.

## Het audit-logboek

Alles wat een ticket veranderde, wie het veranderde en wanneer. Veldwijzigingen, toewijzingen, overgangen, labelwijzigingen, verwijderingen.

Het bestaat voor twee momenten: uitzoeken wat er met één ticket gebeurd is, en een vraag beantwoorden over wat iemand gedaan heeft. Filter op soort actor om te scheiden wat mensen deden van wat automatisering en inkomende e-mail deden — dat is meestal de eerste zinvolle snede.

Regels zijn niet te bewerken. Dat is het punt ervan.

## Gezondheid

De desk hangt van drie dingen af die in leven moeten zijn: de database, de cache en de queue-worker. De worker is degene die stil faalt, want alles laadt nog gewoon — maar er gaat geen post meer uit, webhooks vuren niet meer, SLA-overschrijdingen worden niet meer opgemerkt en geplande regels draaien niet meer.

Zijn de meldingen gestopt en lijkt de interface in orde, controleer dan eerst de worker.

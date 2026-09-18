# Antwoordsjablonen

De antwoorden die je servicedesk vaak genoeg geeft om ze één keer op te schrijven. Een behandelaar kiest er een in het antwoordvak; een automatiseringsregel verstuurt er een uit zichzelf. Allebei lezen ze dezelfde lijst, zodat de tekst nooit twee versies krijgt.

## Waarom één lijst

De meeste servicedesks houden standaardteksten op twee plekken bij: een gedeeld document waar behandelaars uit kopiëren, en het bericht dat in een automatiseringsregel getypt is. Die twee lopen uit elkaar, en degene die achterloopt is altijd die van de robot — niemand denkt eraan de regel te openen als de desk verandert hoe hij excuses maakt.

Een sjabloon is hier daarom één rij met één tekst, en de twee dingen die het gebruiken lezen dat op het moment van gebruik. Pas de tekst één keer aan en allebei veranderen mee.

## Een sjabloon schrijven

**Naam en omschrijving.** De naam ziet een behandelaar in het keuzemenu; de omschrijving staat eronder. Schrijf de omschrijving als *wanneer* je dit gebruikt, niet wat er staat — dat kan de behandelaar zelf lezen.

**Het antwoord zelf** is gewone opgemaakte tekst. Houd het kort: een standaardantwoord dat eerst bewerkt moet worden voordat het verstuurd kan worden, is een standaardantwoord dat niemand gebruikt.

**Plaatshouders** zet je overal in de tekst, tussen dubbele accolades — `{{ requester.first_name }}`, `{{ ticket.key }}`, `{{ ticket.portal_url }}`. Ze worden op het moment van versturen ingevuld vanuit het ticket. De volledige lijst staat op het bewerkscherm, onder *Plaatshouders die je kunt gebruiken*.

Een plaatshouder die niet herkend wordt, blijft leeg in plaats van dat de klant hem ziet. Dat is bewust — een typefout kost je een ietwat rare zin in plaats van een klant die de machinerie ontdekt. Het betekent ook dat een verkeerd geschreven plaatshouder stil mislukt, dus lees je sjabloon terug voordat je opslaat.

`{{ agent.first_name }}` is degene die verstuurt. Een automatiseringsregel is niemand, dus daar blijft het leeg: onderteken een automatisch antwoord er niet mee.

## Reactie of interne notitie

Een sjabloon is het een of het ander, en die keuze zit op het sjabloon en niet op het moment van versturen.

Dat is met opzet. Een interne notitie als *"doorgezet naar de leverancier, nog niets tegen ze zeggen"* is precies de zin die nooit bij een klant mag komen, en de manier waarop dat toch gebeurt is een vinkje dat verkeerd staat. Een notitiesjabloon wordt alleen op het notitietabblad aangeboden, en een automatiseringsregel die het verstuurt plaatst het als notitie, wat de regel verder ook zegt.

## Wie wat ziet

**Team.** Een sjabloon zonder team wordt aan iedereen aangeboden. Een sjabloon van een team wordt aan de behandelaars van dat team aangeboden, en een automatiseringsregel mag het alleen op tickets van dat team versturen.

**Taal.** Een sjabloon zonder taal wordt altijd aangeboden. Een sjabloon met een taal wordt aangeboden als *de melder* die taal leest — niet de behandelaar. Een Nederlandse behandelaar die een Engelse klant antwoordt, heeft de Engelse tekst nodig.

Automatiseringsregels wisselen niet van taal: een regel noemt één sjabloon en verstuurt dat. Wil je dat een regel in de taal van de melder antwoordt, maak dan één regel per taal met een bijpassende voorwaarde.

## Een sjabloon uitzetten

Een sjabloon verwijderen laat automatiseringsregels die ernaar verwijzen achter met niets om te versturen. De regel mislukt niet stilletjes — het uitvoeringslog meldt dat het sjabloon weg is — maar het antwoord gaat ook niet uit.

Uitzetten in plaats van verwijderen houdt het uit het keuzemenu van behandelaars en uit dat van de automatisering, terwijl de regels die ernaar verwijzen hun instellingen houden. Ga je tekst uitfaseren, zet hem dan eerst uit, controleer of er niets stukgaat, en verwijder hem daarna pas.

## Er een versturen vanuit een regel

Voeg in **Beheer → Automatisering** een actie *Verstuur een antwoordsjabloon* toe en kies het sjabloon. Het wordt als gewone reactie geplaatst: vastgelegd in de audittrail, gemaild naar de melder als het een reactie is, en op het ticket te zien zoals die van een behandelaar — alleen zonder auteur, want de servicedesk verstuurde het en niet een persoon.

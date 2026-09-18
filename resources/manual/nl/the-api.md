# De API

Ticktz heeft een REST-API voor de dingen die niet met de hand gedaan zouden moeten worden: een monitoringsysteem dat tickets aanmaakt, een script dat een partij sluit, een ander systeem dat leest wat er openstaat.

## Tokens

**Instellingen → API-tokens** is waar je er één aanmaakt. Een token handelt als *jou*: het kan wat jij kunt en niet meer, dus een token van iemand die geen tickets mag verwijderen kan dat evenmin.

Twee dingen over tokens die je serieus moet nemen:

- **Je ziet hem één keer.** Hij wordt getoond bij het aanmaken en daarna nooit meer. Bewaar hem meteen daar waar het ding dat hem gebruikt hem vindt.
- **Geef de smalste scopes die werken.** Een token dat alleen tickets indient hoort alleen `tickets.write` te hebben. Een scope is een tweede begrenzing bovenop je rechten, geen vervanging ervan — een token is de doorsnede van waarvoor het is uitgegeven en wat de eigenaar mag.

Trek een token in zodra het ding dat hem gebruikte uit dienst gaat. Een ongebruikt token is een sleutel waar niemand naar kijkt.

## Wat het kan

Tickets lezen, indienen, bijwerken en door hun workflow bewegen; reacties lezen en plaatsen. De volledige beschrijving — elk endpoint, elk veld, elke fout — staat in `docs/openapi.yaml` in de repository, en dat is het document om mee te werken, niet deze pagina.

Twee gedragingen die het waard zijn te kennen voor je ertegenaan schrijft:

- **Elk veld bij een update is optioneel**, en alleen wat je stuurt wordt aangeraakt. Een ticket lezen, één veld wijzigen en het hele object terugsturen kan niet overschrijven wat iemand anders er tussendoor veranderde.
- **Status is niet rechtstreeks te wijzigen.** Dat gaat via het overgangs-endpoint, dat de workflow respecteert. Dat is met opzet: een script dat elke status kon zetten, kon een ticket sluiten zonder SLA-registratie en zonder spoor van waarom.

## Limieten en fouten

Verzoeken zijn per token gelimiteerd. Fouten komen terug in één vorm, met een code waarop je kunt vertakken in plaats van een tekst die je zou moeten matchen.

Een toewijzing die geweigerd wordt omdat de persoon niet in het team van het ticket zit, ziet eruit als elke andere validatiefout, en zegt dat in het veld. Scripts die toewijzen moeten daarop rekenen.

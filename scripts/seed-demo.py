#!/usr/bin/env python3
"""Beispieldaten für eine DEV-Instanz von nc_nexus anlegen (über die OCS-API).

    python3 scripts/seed-demo.py [--url http://localhost:8092]

Voraussetzung: Die Demo-Konten existieren (scripts/seed-demo.sh legt sie per occ an).
Gedacht für eine frische Instanz (./dev.sh destroy && ./dev.sh up); auf einer Instanz mit
Daten bricht das Skript beim ersten bereits vorhandenen Kürzel ab.
"""
import argparse
import base64
import datetime
import json
import os
import sys
import urllib.error
import urllib.request

PASSWORD = os.environ.get('DEMO_PASSWORD', 'demo-Pass123!')
ADMIN = ('admin', os.environ.get('NX_ADMIN_PASSWORD', 'admin123'))
API = '/ocs/v2.php/apps/nc_nexus/api/v1'


class Client:
    def __init__(self, base, user, password):
        self.base = base.rstrip('/')
        token = base64.b64encode(f'{user}:{password}'.encode()).decode()
        self.headers = {
            'Authorization': f'Basic {token}',
            'OCS-APIREQUEST': 'true',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        }

    def call(self, method, path, data=None):
        body = None if data is None else json.dumps(data).encode()
        req = urllib.request.Request(self.base + API + path, data=body, method=method, headers=self.headers)
        try:
            with urllib.request.urlopen(req) as res:
                return json.loads(res.read())['ocs']['data']
        except urllib.error.HTTPError as e:
            sys.exit(f'{method} {path}: {e.code} {e.read().decode()[:300]}')

    def get(self, path):
        return self.call('GET', path)

    def post(self, path, data=None):
        return self.call('POST', path, data or {})

    def put(self, path, data):
        return self.call('PUT', path, data)


def month(offset=0):
    d = datetime.date.today().replace(day=1)
    m = d.month - 1 + offset
    return f'{d.year + m // 12}-{m % 12 + 1:02d}'


def day(offset):
    return (datetime.date.today() + datetime.timedelta(days=offset)).isoformat()


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--url', default='http://localhost:8092')
    args = parser.parse_args()

    admin = Client(args.url, *ADMIN)
    anna = Client(args.url, 'anna', PASSWORD)
    ben = Client(args.url, 'ben', PASSWORD)
    paula = Client(args.url, 'paula', PASSWORD)
    sven = Client(args.url, 'sven', PASSWORD)

    print('Organisation und Rollen …')
    it = admin.post('/org-units', {'name': 'IT', 'costCenter': '4100'})
    infra = admin.post('/org-units', {'name': 'Infrastruktur', 'parentId': it['id']})
    apps = admin.post('/org-units', {'name': 'Anwendungen', 'parentId': it['id']})
    sales = admin.post('/org-units', {'name': 'Vertrieb', 'costCenter': '2100'})
    finance = admin.post('/org-units', {'name': 'Finanzen', 'costCenter': '3100'})
    for role, scope, user in [
        ('portfolio_manager', None, 'paula'),
        ('board_member', None, 'boris'),
        ('pool_manager', it['id'], 'sven'),
        ('sponsor', sales['id'], 'anna'),
    ]:
        admin.post('/role-assignments', {
            'role': role, 'scopeType': 'global' if scope is None else 'org_unit', 'scopeId': scope,
            'principalType': 'user', 'principalId': user,
        })

    print('Strategische Ziele …')
    s1 = admin.post('/objectives', {'code': 'S1', 'title': 'Kernprozesse digitalisieren', 'ownerUserId': 'boris'})
    s11 = admin.post('/objectives', {'code': 'S1.1', 'title': 'Rechnungsprozess automatisieren', 'parentId': s1['id'], 'targetMetric': 'Durchlaufzeit je Rechnung < 2 Tage'})
    s12 = admin.post('/objectives', {'code': 'S1.2', 'title': 'Self-Service für Kunden', 'parentId': s1['id'], 'targetMetric': '40 % der Anfragen im Portal'})
    s2 = admin.post('/objectives', {'code': 'S2', 'title': 'Informationssicherheit erhöhen', 'ownerUserId': 'paula'})
    admin.post('/objectives', {'code': 'S3', 'title': 'Betriebskosten senken', 'targetMetric': '−10 % IT-Betriebskosten bis 2028'})

    print('Ressourcenpools …')
    pools = {}
    for code, name, kind, fte, bottleneck, unit, manager in [
        ('SEC', 'IT-Security', 'internal', 3, True, it['id'], 'sven'),
        ('SAP', 'SAP-Basis', 'internal', 2, True, apps['id'], None),
        ('DEV', 'Softwareentwicklung', 'internal', 6, False, apps['id'], None),
        ('FB-VT', 'Fachbereich Vertrieb', 'business', 2, True, sales['id'], None),
    ]:
        pool = admin.post('/pools', {'code': code, 'name': name, 'poolType': kind, 'isBottleneck': bottleneck,
                                     'orgUnitId': unit, 'managerUserId': manager or ''})
        admin.put(f"/pools/{pool['id']}/capacity/{month()}", {'fte': fte})
        pools[code] = pool['id']

    model = admin.get('/scoring/model')
    criteria = model['set']['criteria']

    def scores(benefit, urgency, risk):
        values = {'benefit': benefit, 'urgency': urgency, 'risk': risk}
        return {c['code']: values[c['dimension']] for c in criteria}

    def demand(client, title, kind, problem, unit, needs, objectives=(), deadline=None, benefit=None, desc=None):
        d = client.post('/demands', {'title': title, 'demandType': kind, 'problemStatement': problem,
                                     'orgUnitId': unit, 'deadline': deadline, 'expectedBenefit': benefit,
                                     'description': desc})
        if needs:
            client.put(f"/demands/{d['id']}/needs", {'needs': [
                {'poolId': pools[p], 'period': month(m), 'personDays': days} for p, m, days in needs]})
        if objectives:
            client.put(f"/demands/{d['id']}/objectives", {'objectiveIds': [o['id'] for o in objectives]})
        return d

    def business_case(client, d, capex, annual, benefits):
        client.put(f"/demands/{d['id']}/business-case", {'content': {
            'costs': [{'label': 'Umsetzung', 'kind': 'capex', 'amount': capex},
                      {'label': 'Betrieb und Lizenzen', 'kind': 'opex_annual', 'amount': annual}],
            'benefits': benefits,
            'assumptions': [{'text': 'Fachbereich stellt Key User für den Test', 'ownerUserId': 'paula', 'checkDate': day(60)}],
            'discountRate': 5,
        }})

    print('Anträge …')
    # 1 · freigegeben (G3) mit Business Case und harter Zusage
    invoice = demand(ben, 'Rechnungsworkflow automatisieren', 'efficiency',
                     'Eingangsrechnungen werden manuell erfasst; Skonto geht verloren, Durchlaufzeit 9 Tage.',
                     finance['id'], [('DEV', 0, 15), ('SAP', 1, 8), ('DEV', 1, 10)], [s11, s1],
                     benefit='Durchlaufzeit unter 2 Tage, Skonto wird genutzt.')
    ben.post(f"/demands/{invoice['id']}/submit")
    paula.post(f"/demands/{invoice['id']}/triage", {'code': 'accepted', 'projectClass': 'M'})
    ben.post(f"/demands/{invoice['id']}/assessments", {'kind': 'self', 'scores': scores(5, 3, 3), 'effort': 3})
    paula.post(f"/demands/{invoice['id']}/assessments", {'kind': 'review', 'scores': scores(5, 3, 1), 'effort': 3, 'estimateQuality': 'plausible', 'bucket': 'C'})
    business_case(ben, invoice, 85000, 12000, [
        {'type': 'cost_saving', 'title': 'Skonto-Nutzung', 'amount': 45000, 'metric': 'genutztes Skonto p. a.', 'baseline': '8 000 €', 'target': '53 000 €', 'ownerUserId': 'ben'},
        {'type': 'capacity', 'title': 'Entlastung Kreditorenbuchhaltung', 'amount': 30000, 'metric': 'Bearbeitungszeit je Rechnung', 'baseline': '14 min', 'target': '4 min', 'ownerUserId': 'ben'},
    ])
    for step in [{'step': 'candidate'}, {'step': 'g2', 'outcome': 'prioritized', 'reason': 'Hohe Wertdichte, Skonto-Effekt sofort wirksam'}, {'step': 'g3'}]:
        paula.post(f"/demands/{invoice['id']}/gate", step)

    # 2 · Kandidat, konkurriert in Bucket C
    crm = demand(anna, 'CRM-Ablösung', 'strategic', 'Das CRM ist am Ende der Wartung; Vertrieb pflegt Kundendaten doppelt.',
                 sales['id'], [('DEV', 1, 40), ('DEV', 2, 40), ('FB-VT', 1, 20), ('SEC', 2, 10)], [s1])
    anna.post(f"/demands/{crm['id']}/submit")
    paula.post(f"/demands/{crm['id']}/triage", {'code': 'accepted', 'projectClass': 'L'})
    paula.post(f"/demands/{crm['id']}/assessments", {'kind': 'review', 'scores': scores(5, 5, 5), 'effort': 8, 'bucket': 'C'})
    business_case(anna, crm, 420000, 60000, [
        {'type': 'revenue', 'title': 'Höhere Abschlussquote', 'amount': 150000, 'metric': 'Abschlussquote', 'baseline': '18 %', 'target': '21 %', 'ownerUserId': 'anna'},
    ])
    paula.post(f"/demands/{crm['id']}/gate", {'step': 'candidate'})

    # 3 · Kandidat, kleiner und schneller wirksam
    portal = demand(anna, 'Self-Service-Portal', 'strategic', 'Kunden rufen für Standardauskünfte an; die Hotline ist überlastet.',
                    sales['id'], [('DEV', 1, 20), ('SEC', 1, 4)], [s12])
    anna.post(f"/demands/{portal['id']}/submit")
    paula.post(f"/demands/{portal['id']}/triage", {'code': 'accepted', 'projectClass': 'S'})
    paula.post(f"/demands/{portal['id']}/assessments", {'kind': 'review', 'scores': scores(3, 1, 3), 'effort': 3, 'bucket': 'C'})
    business_case(anna, portal, 60000, 8000, [
        {'type': 'capacity', 'title': 'Entlastung Hotline', 'amount': 35000, 'metric': 'Anrufe pro Monat', 'baseline': '4 200', 'target': '2 500', 'ownerUserId': 'anna'},
    ])
    paula.post(f"/demands/{portal['id']}/gate", {'step': 'candidate'})

    # 4 · Pflichtvorhaben (Bucket A) mit Frist, in Qualifizierung
    nis2 = demand(ben, 'NIS-2-Umsetzung', 'mandatory', 'Gesetzliche Pflicht; ohne Umsetzung drohen Bußgelder und Haftung der Leitung.',
                  it['id'], [('SEC', 0, 20), ('SEC', 1, 25)], [s2], deadline=day(150))
    ben.post(f"/demands/{nis2['id']}/submit")
    paula.post(f"/demands/{nis2['id']}/triage", {'code': 'accepted', 'projectClass': 'M'})
    paula.post(f"/demands/{nis2['id']}/assessments", {'kind': 'review', 'scores': scores(0, 8, 3), 'effort': 5, 'bucket': 'A'})

    # 5 · eingereicht, wartet auf Triage
    fw = demand(ben, 'Firewall-Erneuerung', 'lifecycle', 'Die Firewalls erreichen das Supportende; Sicherheitsupdates fehlen dann.',
                infra['id'], [('SEC', 2, 6)], [s2])
    ben.post(f"/demands/{fw['id']}/submit")

    # 6 · weitere Analyse durch den Pool-Manager
    dp = demand(anna, 'Datenplattform für Vertriebsanalysen', 'innovation', 'Vertriebszahlen liegen in fünf Systemen; Auswertungen dauern Tage.',
                sales['id'], [('DEV', 3, 30)], [s1])
    anna.post(f"/demands/{dp['id']}/submit")
    paula.post(f"/demands/{dp['id']}/triage", {'code': 'further_analysis', 'analysisOwner': 'sven', 'analysisDue': day(21),
                                                'reason': 'Datenschutz und Sicherheitsarchitektur vorab klären'})

    # 7 · zurückgestellt mit Wiedervorlage
    ki = demand(anna, 'KI-Assistent im Kundenservice', 'innovation', 'Antwortzeiten im Kundenservice steigen.', sales['id'], [], [s12])
    anna.post(f"/demands/{ki['id']}/submit")
    paula.post(f"/demands/{ki['id']}/triage", {'code': 'deferred', 'resubmissionDate': day(75), 'reason': 'Erst nach dem Self-Service-Portal sinnvoll'})

    # 8 · abgelehnt
    tickets = demand(ben, 'Zweites Ticketsystem für die Fachabteilung', 'efficiency', 'Die Fachabteilung möchte ein eigenes Werkzeug.', apps['id'], [])
    ben.post(f"/demands/{tickets['id']}/submit")
    paula.post(f"/demands/{tickets['id']}/triage", {'code': 'rejected', 'reason': 'Das bestehende Ticketsystem deckt den Bedarf; Mandantentrennung wird dort eingerichtet.'})

    # 9 · Rückfrage mit Kommentar
    printer = demand(ben, 'Druckerflotte tauschen', 'lifecycle', 'Viele Drucker sind älter als acht Jahre.', infra['id'], [('DEV', 2, 3)], [])
    ben.post(f"/demands/{printer['id']}/submit")
    paula.post(f"/demands/{printer['id']}/query", {'question': 'Wie viele Geräte sind betroffen und gibt es einen Rahmenvertrag?', 'due': day(14)})

    # 10 · Entwurf (nur für ben sichtbar)
    demand(ben, 'Monitoring vereinheitlichen', 'efficiency', '', infra['id'], [])

    sven.post(f"/demands/{dp['id']}/comments", {'message': 'Ich prüfe mit dem Datenschutz die Anonymisierung; Ergebnis bis zur Frist.'})
    print('Fertig. Anmelden z. B. als paula (PMO), boris (Board), anna oder ben – Passwort siehe DEMO_PASSWORD.')


if __name__ == '__main__':
    main()

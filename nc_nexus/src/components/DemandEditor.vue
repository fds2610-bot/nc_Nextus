<template>
	<!-- label-id statt :name: NcModals :name rendert einen sichtbaren <h2>, der auf NC 34 die
	     zentrierte Suchleiste überlagert (VertragsWerk #337). Der Titel steht deshalb nur für
	     Screenreader im Body; sichtbar ist der Zusammenfassungskopf. -->
	<NcModal size="large"
		label-id="nx-demand-editor-title"
		:additional-trap-elements="trapElements"
		:close-on-click-outside="false"
		@close="$emit('close')">
		<!-- Kopf- und Fußzeile stehen fest, nur der Inhalt dazwischen scrollt. -->
		<div class="editor"
			:class="{ 'editor--readonly': readonly }"
			data-test="demand-editor"
			@keydown.esc="onEscape">
			<h2 id="nx-demand-editor-title" class="nx-hidden-visually">
				{{ heading }}
			</h2>
			<header class="editor__head nx-summary">
				<div class="nx-summary__top">
					<div class="nx-summary__id">
						<div class="nx-summary__title">
							{{ heading }}
						</div>
						<div class="nx-summary__sub">
							<template v-if="demand">
								<span data-test="demand-key">{{ demand.key }}</span> · {{ t('nc_nexus', 'by {user}', { user: demand.requestorId }) }}
							</template>
							<template v-else>
								{{ t('nc_nexus', 'Saved as a draft first; only submitting makes it binding.') }}
							</template>
						</div>
					</div>
					<span v-if="demand"
						class="nx-chip"
						:class="'nx-chip--' + statusChip(demand.status)"
						data-test="demand-status">{{ statusLabel(demand.status) }}</span>
				</div>
				<div v-if="demand" class="nx-summary__facts">
					<div class="nx-fact">
						{{ t('nc_nexus', 'Type') }}
						<b>{{ typeLabel(demand.demandType) }}</b>
					</div>
					<div v-if="orgUnitPath" class="nx-fact">
						{{ t('nc_nexus', 'Organizational unit') }}
						<b>{{ orgUnitPath }}</b>
					</div>
					<div class="nx-fact">
						{{ t('nc_nexus', 'Created') }}
						<b>{{ formatDay(demand.createdAt) }}</b>
					</div>
					<div v-if="demand.submittedAt" class="nx-fact">
						{{ t('nc_nexus', 'Submitted') }}
						<b>{{ formatDay(demand.submittedAt) }}</b>
					</div>
					<div class="nx-fact">
						{{ t('nc_nexus', 'Channel') }}
						<b>{{ channelLabel(demand.intakeChannel) }}</b>
					</div>
					<div v-if="demand.projectKey" class="nx-fact">
						{{ t('nc_nexus', 'Project') }}
						<b data-test="project-key">{{ demand.projectKey }}</b>
					</div>
					<div v-if="demand.projectClass" class="nx-fact">
						{{ t('nc_nexus', 'Project class') }}
						<b>{{ demand.projectClass }}</b>
					</div>
				</div>
			</header>

			<div class="editor__body">
				<div v-if="loading" class="editor__loading">
					<NcLoadingIcon :size="32" />
				</div>
				<form v-else @submit.prevent="save">
					<div v-if="demand && demand.status === 'query'" class="editor__notice" data-test="query-notice">
						<strong>{{ t('nc_nexus', 'Query from the portfolio office') }}</strong>
						<span v-if="demand.queryDue"> · {{ t('nc_nexus', 'answer by {date}', { date: formatIsoDay(demand.queryDue) }) }}</span>
						<p>{{ demand.queryText }}</p>
						<template v-if="demand.can.edit">
							<label class="nx-field editor__answer" data-test="field-answer">
								<span>{{ t('nc_nexus', 'Your answer (optional)') }}</span>
								<textarea v-model="answer" class="nx-input" />
							</label>
							<p class="nx-hint">
								{{ t('nc_nexus', 'Complete the demand and submit it again; the answer appears in the comments.') }}
							</p>
						</template>
					</div>

					<!-- Für das PMO: mögliche Dubletten vor der Triage -->
					<SimilarDemands v-if="demand && demand.can.triage"
						mode="triage"
						:title="demand.title"
						:problem="demand.problemStatement || ''"
						:exclude-id="demand.id" />

					<!-- Nächster Schritt für das PMO, solange noch keine Triage-Entscheidung vorliegt (z. B. Rückfrage) -->
					<p v-if="demand && !demand.triageCode && demand.can.triager && nextStep"
						class="editor__next"
						data-test="triage-next">
						{{ nextStep }}
					</p>

					<div class="editor__group editor__group--request" data-test="group-request">
						<h3 class="editor__group-title">
							{{ t('nc_nexus', 'Request') }}
						</h3>
						<div class="nx-section">
							<h3>{{ t('nc_nexus', 'Basic data') }}</h3>
							<div class="nx-field-row">
								<label class="nx-field" data-test="field-title">
									<span>{{ t('nc_nexus', 'Title') }} *</span>
									<input v-model="form.title"
										class="nx-input"
										maxlength="255"
										:disabled="readonly"
										:placeholder="readonly ? '' : t('nc_nexus', 'e.g. Replace the ticket system')">
								</label>
								<label class="nx-field" data-test="field-type">
									<span>{{ t('nc_nexus', 'Type') }} *</span>
									<select v-model="form.demandType" class="nx-input" :disabled="readonly">
										<option v-for="type in types" :key="type" :value="type">
											{{ typeLabel(type) }}
										</option>
									</select>
								</label>
							</div>
							<!-- Dublettenvorschlag beim Tippen (DEM-001) -->
							<SimilarDemands v-if="!readonly"
								:title="form.title"
								:problem="form.problemStatement"
								:exclude-id="id" />
							<label class="nx-field" data-test="field-org-unit">
								<span>{{ t('nc_nexus', 'Organizational unit') }}</span>
								<select v-model="form.orgUnitId" class="nx-input" :disabled="readonly">
									<option :value="null">{{ t('nc_nexus', '– none –') }}</option>
									<option v-for="unit in orgTreeList" :key="unit.id" :value="unit.id">
										{{ indented(unit) }}
									</option>
								</select>
								<p v-if="!readonly" class="nx-hint">{{ t('nc_nexus', 'Determines who besides you can see the demand (sponsor, PMO, board of the area).') }}</p>
							</label>
							<label class="nx-field" data-test="field-deadline">
								<span>{{ t('nc_nexus', 'Deadline (e.g. legal or contractual date)') }}</span>
								<input v-model="form.deadline"
									type="date"
									class="nx-input editor__date"
									:disabled="readonly">
							</label>
						</div>

						<div class="nx-section">
							<h3>{{ t('nc_nexus', 'Justification') }}</h3>
							<label class="nx-field" data-test="field-problem">
								<span>{{ t('nc_nexus', 'Problem statement') }} *</span>
								<textarea v-model="form.problemStatement"
									class="nx-input"
									:disabled="readonly"
									:placeholder="readonly ? '' : t('nc_nexus', 'What happens if we do nothing?')" />
								<p v-if="!readonly" class="nx-hint">{{ t('nc_nexus', 'Required for submitting.') }}</p>
							</label>
							<label class="nx-field" data-test="field-benefit">
								<span>{{ t('nc_nexus', 'Expected benefit') }}</span>
								<textarea v-model="form.expectedBenefit" class="nx-input" :disabled="readonly" />
							</label>
						</div>

						<div class="nx-section">
							<h3>{{ t('nc_nexus', 'Description') }}</h3>
							<label class="nx-field" data-test="field-description">
								<span class="nx-hidden-visually">{{ t('nc_nexus', 'Description') }}</span>
								<textarea v-model="form.description"
									class="nx-input"
									:disabled="readonly"
									:placeholder="readonly ? '' : t('nc_nexus', 'Scope, affected areas, desired time frame …')" />
							</label>
						</div>

						<div v-if="objectives.length" class="nx-section" data-test="demand-objectives">
							<h3>{{ t('nc_nexus', 'Strategic objectives') }}</h3>
							<p v-if="!readonly" class="nx-hint editor__objectives-hint">
								{{ t('nc_nexus', 'Which objectives does the demand contribute to?') }}
							</p>
							<p v-if="readonly && !objectiveIds.length" class="editor__muted">
								{{ t('nc_nexus', 'Not assigned to any objective.') }}
							</p>
							<ul class="editor__objectives">
								<li v-for="o in objectiveList"
									v-show="!readonly || objectiveIds.includes(o.id)"
									:key="o.id"
									:style="{ paddingInlineStart: (readonly ? 0 : o.depth * 20) + 'px' }">
									<label>
										<input v-if="!readonly"
											v-model="objectiveIds"
											type="checkbox"
											:value="o.id"
											:data-test="'objective-check-' + o.code">
										<span class="editor__objective-code">{{ o.code }}</span> {{ o.title }}
									</label>
								</li>
							</ul>
						</div>
					</div>

					<div class="editor__group editor__group--economics" data-test="group-economics">
						<h3 class="editor__group-title">
							{{ t('nc_nexus', 'Economics and resources') }}
						</h3>
						<div v-if="demand && businessCase && (businessCase.canEdit || businessCase.versions.length)"
							class="nx-section"
							data-test="demand-business-case">
							<h3>{{ t('nc_nexus', 'Business case') }}</h3>
							<div v-if="businessCase.versions.length" class="nx-summary__facts editor__triage">
								<div class="nx-fact">
									{{ t('nc_nexus', 'Benefit p. a.') }}<b data-test="bc-summary-benefit">{{ formatMoney(businessCase.kpis.benefitPerYear) }}</b>
								</div>
								<div class="nx-fact">
									{{ t('nc_nexus', 'One-time cost') }}<b>{{ formatMoney(businessCase.kpis.oneTimeCost) }}</b>
								</div>
								<div class="nx-fact">
									{{ t('nc_nexus', 'Payback') }}<b>{{ businessCase.kpis.paybackYears === null ? '–' : t('nc_nexus', '{y} years', { y: businessCase.kpis.paybackYears }) }}</b>
								</div>
							</div>
							<p v-else class="editor__muted">
								{{ t('nc_nexus', 'No business case yet.') }}
							</p>
							<p v-if="businessCase.versions.length && !businessCase.complete" class="editor__next">
								{{ t('nc_nexus', 'Incomplete: at least one cost item and one benefit item are needed for prioritization.') }}
							</p>
							<div class="editor__rating-actions">
								<NcButton variant="secondary" data-test="open-business-case" @click="bcOpen = true">
									{{ businessCase.canEdit ? t('nc_nexus', 'Edit business case') : t('nc_nexus', 'Show business case') }}
								</NcButton>
							</div>
						</div>

						<div v-if="demand && rating && (rating.assessments.length || rating.can.self || rating.can.review)"
							class="nx-section"
							data-test="demand-rating">
							<h3>{{ t('nc_nexus', 'Assessment') }}</h3>
							<div v-if="rating.current" class="nx-summary__facts editor__triage">
								<div class="nx-fact">
									{{ t('nc_nexus', 'Value density') }}
									<b data-test="rating-density">{{ formatNumber2(rating.current.density) }}</b>
								</div>
								<div class="nx-fact">
									{{ t('nc_nexus', 'Benefit') }}
									<b>{{ formatNumber2(rating.current.benefit) }}</b>
								</div>
								<div class="nx-fact">
									{{ t('nc_nexus', 'Urgency') }}
									<b>{{ rating.current.urgency }}</b>
								</div>
								<div class="nx-fact">
									{{ t('nc_nexus', 'Risk') }}
									<b>{{ formatNumber2(rating.current.risk) }}</b>
								</div>
								<div class="nx-fact">
									{{ t('nc_nexus', 'Effort class') }}
									<b>{{ rating.current.effort }} · {{ qualityLabel(rating.current.estimateQuality) }}</b>
								</div>
								<div class="nx-fact">
									{{ t('nc_nexus', 'Bucket') }}
									<b>{{ rating.bucket }}</b>
								</div>
							</div>
							<p v-else class="editor__muted">
								{{ t('nc_nexus', 'Not assessed yet.') }}
							</p>
							<p v-if="rating.current" class="editor__next">
								{{ assessmentKindLabel(rating.current.kind) }} · {{ rating.current.createdBy }} · {{ formatDay(rating.current.createdAt) }}
								<template v-if="rating.current.selfDensity !== null && rating.current.reviewDensity !== null">
									· {{ t('nc_nexus', 'self-assessment {s}, review {r}', { s: formatNumber2(rating.current.selfDensity), r: formatNumber2(rating.current.reviewDensity) }) }}
								</template>
							</p>
							<div class="editor__rating-actions">
								<NcButton v-if="rating.can.self"
									variant="secondary"
									data-test="assess-self"
									@click="assessKind = 'self'">
									{{ t('nc_nexus', 'Self-assessment') }}
								</NcButton>
								<NcButton v-if="rating.can.review"
									variant="secondary"
									data-test="assess-review"
									@click="assessKind = 'review'">
									{{ t('nc_nexus', 'Review assessment') }}
								</NcButton>
							</div>
						</div>

						<NeedsEditor v-model="needs"
							:pools="pools"
							:readonly="readonly"
							:project-class="demand?.projectClass ?? null" />
					</div>

					<div class="editor__group editor__group--decision" data-test="group-decision">
						<h3 class="editor__group-title">
							{{ t('nc_nexus', 'Decision and implementation') }}
						</h3>
						<div v-if="demand && demand.triageCode" class="nx-section" data-test="triage-result">
							<h3>{{ t('nc_nexus', 'Triage') }}</h3>
							<div class="nx-summary__facts editor__triage">
								<div class="nx-fact">
									{{ t('nc_nexus', 'Decision') }}
									<b>{{ statusLabel(demand.status) }}</b>
								</div>
								<div v-if="demand.resubmissionDate" class="nx-fact">
									{{ t('nc_nexus', 'Resubmission') }}
									<b>{{ formatIsoDay(demand.resubmissionDate) }}</b>
								</div>
								<div v-if="demand.analysisOwner" class="nx-fact">
									{{ t('nc_nexus', 'Analysis by') }}
									<b>{{ demand.analysisOwner }}</b>
								</div>
								<div v-if="demand.analysisDue" class="nx-fact">
									{{ t('nc_nexus', 'Analysis due') }}
									<b>{{ formatIsoDay(demand.analysisDue) }}</b>
								</div>
								<div v-if="demand.duplicateOfId" class="nx-fact">
									{{ t('nc_nexus', 'Duplicate of') }}
									<b>{{ duplicateLabel }}</b>
								</div>
								<div v-if="demand.triagedBy" class="nx-fact">
									{{ t('nc_nexus', 'Decided by') }}
									<b>{{ demand.triagedBy }} · {{ formatDay(demand.triagedAt) }}</b>
								</div>
							</div>
							<p v-if="nextStep" class="editor__next" data-test="triage-next">
								{{ nextStep }}
							</p>
							<p v-if="demand.triageReason" class="editor__reason">
								{{ demand.triageReason }}
							</p>
						</div>

						<div v-if="demand && gate && (gate.step || gate.allocations.length || gate.conditions.length || PROJECT_STATES.includes(demand.status))" class="nx-section" data-test="demand-gate">
							<h3>{{ t('nc_nexus', 'Gates') }}</h3>
							<ol v-if="PROJECT_STATES.includes(demand.status) || gate.step && gate.step !== 'candidate'" class="editor__path" data-test="gate-path">
								<li v-for="g in gate.gateSet.gates"
									:key="g.code"
									:class="'editor__path--' + g.state"
									:title="g.name">
									{{ g.code.toUpperCase() }}
								</li>
								<li class="editor__path-set">
									{{ gate.gateSet.name }}
								</li>
							</ol>
							<template v-for="step in (gate.steps.length ? gate.steps : [])" :key="step">
								<p class="editor__step">
									<b>{{ gateLabel(step) }}</b>
									<span v-if="step !== 'candidate'" data-test="gate-decider">
										· {{ gate.decider.kind === 'body'
											? t('nc_nexus', 'decided by {body} in a session', { body: gate.decider.body.name })
											: t('nc_nexus', 'decided directly by the board or the portfolio office of the area') }}
									</span>
									<span v-if="gate.instance && step === gate.step" class="nx-chip nx-chip--submitted" data-test="gate-instance">
										{{ instanceStatusLabel(gate.instance.status) }}
									</span>
								</p>
							</template>
							<ul v-if="gate.step" class="editor__checks">
								<li v-for="c in gate.checks" :key="c.key" :class="c.ok ? 'editor__check--ok' : 'editor__check--fail'">
									{{ c.ok ? '✓' : '✗' }} {{ checkLabel(c.key) }}<template v-if="!c.ok && c.detail">
										· {{ c.detail }}
									</template>
								</li>
							</ul>
							<p v-if="gate.step && !gate.canDecide && !gate.canSubmit" class="editor__next">
								{{ gate.step === 'candidate'
									? t('nc_nexus', 'The portfolio office puts the project up for prioritization once everything is complete.')
									: (gate.decider.kind === 'body'
										? t('nc_nexus', 'The portfolio office submits the gate to {body}; the decision is taken in a session.', { body: gate.decider.body.name })
										: t('nc_nexus', 'The board or the portfolio office of the area decides.')) }}
							</p>
							<div v-if="gate.conditions.length" class="editor__conditions" data-test="demand-conditions">
								<h4>{{ t('nc_nexus', 'Conditions and actions') }}</h4>
								<ul>
									<li v-for="c in gate.conditions"
										:key="c.id"
										:class="{ 'editor__condition--overdue': c.overdue, 'editor__condition--closed': c.status !== 'open' }">
										<span class="nx-chip" :class="c.status === 'open' ? (c.blocking ? 'nx-chip--rejected' : 'nx-chip--on-hold') : 'nx-chip--accepted'">
											{{ c.status === 'open' ? (c.blocking ? t('nc_nexus', 'blocking') : t('nc_nexus', 'open')) : (c.status === 'done' ? t('nc_nexus', 'done') : t('nc_nexus', 'waived')) }}
										</span>
										{{ c.title }} · {{ c.ownerId }} · {{ formatIsoDay(c.dueDate) }}
									</li>
								</ul>
							</div>
							<p v-if="gate.tranches.length" class="editor__tranches" data-test="demand-tranches">
								{{ t('nc_nexus', 'Budget released') }}: <b>{{ formatMoney(gate.budgetReleased) }}</b>
								<template v-if="businessCase && businessCase.kpis && businessCase.kpis.oneTimeCost">
									{{ t('nc_nexus', 'of {total} one-time cost', { total: formatMoney(businessCase.kpis.oneTimeCost) }) }}
								</template>
								<small>({{ gate.tranches.map(tr => tr.gateCode.toUpperCase() + ' ' + formatMoney(tr.amount) + ' / ' + tr.fiscalYear).join(' · ') }})</small>
							</p>
							<h4 v-if="gate.allocations.length">
								{{ t('nc_nexus', 'Firm commitments') }}
							</h4>
							<table v-if="gate.allocations.length" class="editor__alloc" data-test="allocations">
								<tbody>
									<tr v-for="(a, i) in gate.allocations" :key="i">
										<td>{{ poolName(a.poolId) }}</td>
										<td>{{ formatMonth(a.period) }}</td>
										<td>{{ formatNumber(a.personDays) }} {{ t('nc_nexus', 'PD') }}</td>
									</tr>
								</tbody>
							</table>
						</div>

						<PlanningPanel v-if="demand && ['released', 'in_execution', 'paused', 'completed'].includes(demand.status)"
							:demand-id="demand.id"
							:status="demand.status"
							@changed="onPlanning" />

						<AiPanel v-if="demand && demand.id"
							:demand-id="demand.id"
							:status="demand.status" />
					</div>

					<div class="editor__group editor__group--exchange" data-test="group-exchange">
						<h3 class="editor__group-title">
							{{ t('nc_nexus', 'Discussion and history') }}
						</h3>
						<CommentThread v-if="demand"
							:demand-id="demand.id"
							:refresh-key="commentRefresh"
							@dirty="commentDirty = $event" />

						<div v-if="history.length" class="nx-section" data-test="demand-history">
							<h3>{{ t('nc_nexus', 'History') }}</h3>
							<ul class="editor__history">
								<li v-for="entry in history" :key="entry.id">
									<span class="editor__history-when">{{ formatDate(entry.createdAt) }}</span>
									<span :data-test="'history-' + entry.action">
										<strong>{{ actionLabel(entry.action) }}</strong>
										<!-- Statuswechsel immer mit „alt → neu“ und dem Konto, das ihn ausgelöst hat -->
										<span v-if="statusChange(entry)" class="editor__transition">
											{{ statusLabel(statusChange(entry)[0]) }} → {{ statusLabel(statusChange(entry)[1]) }}
										</span>
										<span class="editor__by">{{ t('nc_nexus', 'by {user}', { user: entry.userId }) }}</span>
										<span v-if="changedFields(entry).length" class="editor__changes">
											· {{ changedFields(entry).map(fieldLabel).join(', ') }}
										</span>
									</span>
								</li>
							</ul>
						</div>
					</div>
				</form>
			</div>

			<footer class="editor__actions">
				<NcButton v-if="canDelete"
					variant="error"
					class="editor__delete"
					:disabled="busy"
					data-test="delete-demand"
					@click="remove">
					{{ t('nc_nexus', 'Delete draft') }}
				</NcButton>
				<!-- „Abbrechen“ nur, wenn ungespeicherte Eingaben verworfen würden; sonst „Schließen“ -->
				<!-- „Schließen“ ist die hervorgehobene Standardaktion, wenn es sonst nichts zu tun gibt -->
				<NcButton ref="closeButton"
					:variant="closeIsDefault ? 'primary' : 'tertiary'"
					:disabled="busy"
					data-test="close-demand"
					@click="$emit('close')">
					{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
				</NcButton>
				<NcButton v-if="demand && demand.can.completeAnalysis"
					variant="primary"
					data-test="open-analysis"
					:disabled="busy"
					@click="analysisOpen = true">
					{{ t('nc_nexus', 'Complete analysis') }}
				</NcButton>
				<!-- Für das PMO sichtbar; ausgegraut, solange keine Triage fällig ist (Grund im Abschnitt „Triage“) -->
				<NcButton v-if="demand && ['released', 'in_execution', 'paused', 'completed', 'closed', 'cancelled'].includes(demand.status)"
					variant="secondary"
					data-test="open-cockpit"
					@click="$emit('open-project', demand.id)">
					{{ t('nc_nexus', 'Project cockpit') }}
				</NcButton>
				<template v-if="demand && gate && gate.canDecide">
					<NcButton v-for="step in gate.steps"
						:key="step"
						:variant="gate.ready && step === gate.step ? 'primary' : 'secondary'"
						:data-test="step === gate.step ? 'open-gate' : 'open-gate-' + step"
						:disabled="busy"
						@click="openGate(step)">
						{{ gateLabel(step) }}
					</NcButton>
				</template>
				<template v-if="demand && gate && gate.canSubmit">
					<NcButton v-for="step in gate.steps"
						:key="'submit-' + step"
						:variant="gate.ready && step === gate.step && !(gate.instance && ['ready', 'scheduled'].includes(gate.instance.status)) ? 'primary' : 'secondary'"
						:data-test="step === gate.step ? 'submit-gate' : 'submit-gate-' + step"
						:disabled="busy || (step === gate.step && gate.instance && ['ready', 'scheduled', 'adjourned'].includes(gate.instance.status))"
						@click="onSubmitGate(step)">
						{{ t('nc_nexus', 'Submit {gate} to {body}', { gate: step.toUpperCase(), body: gate.decider.body.code }) }}
					</NcButton>
				</template>
				<!-- PMO: außerplanmäßig neu entscheiden (Begründung Pflicht), bewusst zurückhaltend -->
				<NcButton v-if="demand && demand.can.retriage"
					variant="tertiary"
					data-test="retriage"
					:disabled="busy"
					@click="openTriage(true)">
					{{ t('nc_nexus', 'Decide again') }}
				</NcButton>
				<NcButton v-if="demand && demand.can.triager"
					:variant="demand.can.triage ? 'primary' : 'secondary'"
					data-test="open-triage"
					:disabled="busy || !demand.can.triage"
					:title="demand.can.triage ? '' : nextStep"
					@click="openTriage(false)">
					{{ t('nc_nexus', 'Triage') }}
				</NcButton>
				<template v-if="!readonly">
					<NcButton variant="secondary"
						:disabled="busy || !valid || (demand && !dirtyForm)"
						data-test="save-demand"
						@click="save">
						{{ !demand || demand.can.submit ? t('nc_nexus', 'Save draft') : t('nc_nexus', 'Save changes') }}
					</NcButton>
					<NcButton v-if="!demand || demand.can.submit"
						variant="primary"
						:disabled="busy || !valid || !form.problemStatement.trim()"
						data-test="submit-demand"
						@click="submit">
						{{ demand && demand.status === 'query' ? t('nc_nexus', 'Submit again') : t('nc_nexus', 'Submit') }}
					</NcButton>
				</template>
			</footer>
		</div>
		<TriageDialog v-if="triageOpen"
			:demand="demand"
			:demands="demands"
			:override="triageOverride"
			@ready="el => trapElements = [el]"
			@close="triageOpen = false; trapElements = []"
			@decided="onDecided" />
		<GateDialog v-if="gateOpen && gate"
			:demand-id="demand.id"
			:title="demand.title"
			:step="gateStep"
			:checks="gateStep === gate.step ? gate.checks : []"
			:outcomes="gate.outcomes"
			:ready="gateStep !== gate.step || gate.ready"
			:tranche="gate.tranche"
			@ready="el => trapElements = [el]"
			@close="gateOpen = false; trapElements = []"
			@decided="onGate" />
		<BusinessCaseDialog v-if="bcOpen && businessCase"
			:demand="demand"
			:business-case="businessCase"
			@ready="el => trapElements = [el]"
			@close="bcOpen = false; trapElements = []"
			@saved="onBusinessCase" />
		<AssessmentDialog v-if="assessKind && scoring"
			:demand="demand"
			:kind="assessKind"
			:model="scoring"
			:suggested-effort="rating.suggestedEffort"
			:bucket="rating.bucket"
			:previous="previousAssessment"
			@ready="el => trapElements = [el]"
			@close="assessKind = null; trapElements = []"
			@saved="onAssessed" />
		<AnalysisDialog v-if="analysisOpen"
			:demand="demand"
			@ready="el => trapElements = [el]"
			@close="analysisOpen = false; trapElements = []"
			@done="onDecided" />
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { getCanonicalLocale, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import { createDemand, deleteDemand, demandAssessments, demandHistory, demandNeeds, demandObjectives, errorMessage, gateStatus, getBusinessCase, getDemand, saveDemandNeeds, saveDemandObjectives, submitDemand, submitGate, updateDemand } from '../api.js'
import AiPanel from './AiPanel.vue'
import AnalysisDialog from './AnalysisDialog.vue'
import AssessmentDialog from './AssessmentDialog.vue'
import BusinessCaseDialog from './BusinessCaseDialog.vue'
import GateDialog from './GateDialog.vue'
import PlanningPanel from './PlanningPanel.vue'
import CommentThread from './CommentThread.vue'
import NeedsEditor from './NeedsEditor.vue'
import SimilarDemands from './SimilarDemands.vue'
import TriageDialog from './TriageDialog.vue'
import { actionLabel, assessmentKindLabel, channelLabel, checkLabel, fieldLabel, formatDate, formatDay, formatIsoDay, formatMoney, formatMonth, formatNumber, gateLabel, indented, instanceStatusLabel, objectiveTree, orgTree, qualityLabel, statusChip, statusLabel, typeLabel } from '../labels.js'

const emptyForm = () => ({ title: '', demandType: 'strategic', orgUnitId: null, deadline: '', problemStatement: '', description: '', expectedBenefit: '' })

export default {
	name: 'DemandEditor',
	components: { AiPanel, AnalysisDialog, AssessmentDialog, BusinessCaseDialog, GateDialog, PlanningPanel, CommentThread, NcButton, NeedsEditor, NcLoadingIcon, NcModal, SimilarDemands, TriageDialog },
	props: {
		demandId: { type: Number, default: null },
		orgUnits: { type: Array, default: () => [] },
		demands: { type: Array, default: () => [] },
		pools: { type: Array, default: () => [] },
		objectives: { type: Array, default: () => [] },
		scoring: { type: Object, default: null },
	},
	emits: ['close', 'changed', 'open-project'],
	setup() {
		return { indented, t, actionLabel, channelLabel, fieldLabel, formatDate, formatDay, formatIsoDay, statusChip, statusLabel, typeLabel, assessmentKindLabel, qualityLabel, formatMoney, checkLabel, gateLabel, formatMonth, formatNumber, instanceStatusLabel, PROJECT_STATES: ['prioritized', 'released', 'in_execution', 'paused', 'completed', 'closed', 'cancelled'] }
	},
	data() {
		return {
			id: this.demandId,
			demand: null,
			form: emptyForm(),
			saved: JSON.stringify(emptyForm()),
			history: [],
			loading: this.demandId !== null,
			busy: false,
			triageOpen: false,
			triageOverride: false,
			needs: [],
			rating: null,
			businessCase: null,
			gate: null,
			gateOpen: false,
			gateStep: null,
			bcOpen: false,
			assessKind: null,
			objectiveIds: [],
			objectivesSaved: '[]',
			needsSaved: '[]',
			// Elemente innerer Dialoge, die zur Fokusfalle dieses Modals gehören
			trapElements: [],
			analysisOpen: false,
			answer: '',
			commentRefresh: 0,
			commentDirty: false,
			types: loadState('nc_nexus', 'demand_types', []),
		}
	},
	computed: {
		// letzte Bewertung derselben Art als Vorbelegung des Dialogs
		previousAssessment() {
			return [...(this.rating?.assessments ?? [])].reverse().find(a => a.kind === this.assessKind) ?? null
		},
		objectiveList() {
			return objectiveTree(this.objectives)
		},
		orgTreeList() {
			return orgTree(this.orgUnits)
		},
		orgUnitPath() {
			return this.orgTreeList.find(u => u.id === this.demand?.orgUnitId)?.path ?? ''
		},
		heading() {
			return this.demand ? this.demand.title : t('nc_nexus', 'New demand')
		},
		readonly() {
			return !!this.demand && !this.demand.can.edit
		},
		canDelete() {
			return !!this.demand && this.demand.can.delete
		},
		// Gibt es außer „Schließen“ eine Aktion (Einreichen, Triage, Analyse)? Sonst ist „Schließen“ Standard.
		closeIsDefault() {
			if (!this.demand) {
				return false
			}
			return !this.dirty && !this.demand.can.submit && !this.demand.can.triage && !this.demand.can.completeAnalysis
				&& !(this.gate?.step && (this.gate.canDecide || this.gate.canSubmit) && this.gate.ready)
		},
		dirtyForm() {
			return !this.readonly && (JSON.stringify(this.form) !== this.saved || JSON.stringify(this.needs) !== this.needsSaved || this.objectivesChanged())
		},
		// Was als Nächstes passiert – führt Antragsteller, Analyse und PMO durch den Ablauf
		nextStep() {
			const d = this.demand
			if (!d) {
				return ''
			}
			switch (d.status) {
			case 'query':
				return t('nc_nexus', 'Waiting for the answer of {user} until {date}; triage is possible again after resubmission.', { user: d.requestorId, date: formatIsoDay(d.queryDue) })
			case 'further_analysis':
				return d.analysisDoneAt
					? t('nc_nexus', 'Analysis delivered – the demand is waiting for triage again.')
					: t('nc_nexus', 'Waiting for the analysis by {user} until {date}; triage is possible again afterwards.', { user: d.analysisOwner, date: formatIsoDay(d.analysisDue) })
			case 'deferred':
				return d.can.triage
					? t('nc_nexus', 'Resubmission is due – the demand is waiting for triage again.')
					: t('nc_nexus', 'Deferred until {date}; triage is possible again from then on.', { date: formatIsoDay(d.resubmissionDate) })
			case 'in_qualification':
			case 'rejected':
			case 'duplicate':
				return t('nc_nexus', 'Triage completed.')
			default:
				return ''
			}
		},
		duplicateLabel() {
			const lead = this.demands.find(d => d.id === this.demand?.duplicateOfId)
			return lead ? `${lead.key} · ${lead.title}` : `#${this.demand?.duplicateOfId}`
		},
		// ungespeicherte Eingaben (Formular, Antwort, begonnener Kommentar) → „Abbrechen“
		dirty() {
			return this.commentDirty || this.answer.trim() !== '' || this.dirtyForm
		},
		valid() {
			return this.form.title.trim() !== '' && !!this.form.demandType
		},
	},
	async mounted() {
		if (this.id !== null) {
			await this.load()
		}
	},
	methods: {
		async load() {
			try {
				this.setDemand(await getDemand(this.id))
				this.setNeeds(await demandNeeds(this.id))
				this.setObjectives(await demandObjectives(this.id))
				this.rating = await demandAssessments(this.id)
				this.businessCase = await getBusinessCase(this.id)
				this.gate = await gateStatus(this.id)
				this.history = await demandHistory(this.id)
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.loading = false
			}
		},
		setDemand(demand) {
			this.demand = demand
			this.id = demand.id
			this.form = {
				title: demand.title,
				demandType: demand.demandType,
				orgUnitId: demand.orgUnitId ?? null,
				deadline: demand.deadline ?? '',
				problemStatement: demand.problemStatement ?? '',
				description: demand.description ?? '',
				expectedBenefit: demand.expectedBenefit ?? '',
			}
			this.saved = JSON.stringify(this.form)
		},
		// [alt, neu] bei einem Statuswechsel, sonst null
		statusChange(entry) {
			const change = entry.fieldChanges?.status
			return Array.isArray(change) && change[0] !== change[1] ? change : null
		},
		// geänderte Felder nur bei Bearbeitungen; Statuswechsel und Entscheidungen zeigen „alt → neu“ und den Kommentar
		changedFields(entry) {
			return entry.action === 'update' && entry.fieldChanges ? Object.keys(entry.fieldChanges) : []
		},
		formatNumber2(value) {
			return Number(value).toLocaleString(getCanonicalLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })
		},
		poolName(id) {
			return this.pools.find(p => p.id === id)?.name ?? '?'
		},
		openGate(step) {
			this.gateStep = step
			this.gateOpen = true
		},
		async onSubmitGate(step) {
			this.busy = true
			try {
				const instance = await submitGate(this.id, step)
				if (instance.status === 'ready') {
					showSuccess(t('nc_nexus', 'Submitted; the gate is ready for the next session'))
				} else {
					showError(t('nc_nexus', 'The checklist is not complete yet'))
				}
				this.gate = await gateStatus(this.id)
				this.history = await demandHistory(this.id)
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async onGate() {
			// über den Antrags-Endpunkt neu laden: nur er liefert die Rechte (can) des Kontos
			this.setDemand(await getDemand(this.id))
			this.$emit('changed')
			this.$nextTick(() => this.$refs.closeButton?.$el?.focus())
			this.gate = await gateStatus(this.id)
			this.businessCase = await getBusinessCase(this.id)
			this.history = await demandHistory(this.id)
		},
		async onPlanning() {
			this.history = await demandHistory(this.id)
		},
		async onBusinessCase(bc) {
			this.businessCase = bc
			this.gate = await gateStatus(this.id)
			this.$emit('changed')
			this.history = await demandHistory(this.id)
		},
		async onAssessed(rating) {
			this.rating = rating
			this.gate = await gateStatus(this.id)
			this.$emit('changed')
			this.setDemand(await getDemand(this.id))
			this.history = await demandHistory(this.id)
		},
		openTriage(override) {
			this.triageOverride = override
			this.triageOpen = true
		},
		onEscape() {
			// Liegt ein innerer Dialog darüber, schließt Esc nur diesen
			if (!this.triageOpen && !this.analysisOpen && !this.assessKind && !this.bcOpen && !this.gateOpen) {
				this.$emit('close')
			}
		},
		async onDecided(demand) {
			this.setDemand(demand)
			// zuerst melden: wird der Dialog vor dem Nachladen geschlossen, ginge das Ereignis sonst verloren
			this.$emit('changed')
			// nach der Entscheidung ist „Schließen“ die naheliegende Aktion
			this.$nextTick(() => this.$refs.closeButton?.$el?.focus())
			this.commentRefresh++
			this.history = await demandHistory(this.id)
		},
		setObjectives(ids) {
			this.objectiveIds = [...ids].sort((a, b) => a - b)
			this.objectivesSaved = JSON.stringify(this.objectiveIds)
		},
		objectivesChanged() {
			return JSON.stringify([...this.objectiveIds].sort((a, b) => a - b)) !== this.objectivesSaved
		},
		setNeeds(needs) {
			this.needs = needs.map(n => ({ poolId: n.poolId, period: n.period, personDays: n.personDays }))
			this.needsSaved = JSON.stringify(this.needs)
		},
		async persist() {
			const demand = this.id === null ? await createDemand(this.form) : await updateDemand(this.id, this.form)
			this.setDemand(demand)
			// Ressourcenbedarf gehört zum Antrag und wird mit ihm gespeichert
			if (JSON.stringify(this.needs) !== this.needsSaved) {
				this.setNeeds(await saveDemandNeeds(this.id, this.needs.filter(n => Number(n.personDays) > 0)))
			}
			if (this.objectivesChanged()) {
				this.setObjectives(await saveDemandObjectives(this.id, this.objectiveIds))
			}
			this.$emit('changed')
		},
		async run(action, message) {
			this.busy = true
			try {
				await action()
				showSuccess(message)
				return true
			} catch (e) {
				showError(errorMessage(e))
				return false
			} finally {
				this.busy = false
			}
		},
		async save() {
			if (!this.valid || this.readonly) {
				return
			}
			if (await this.run(() => this.persist(), t('nc_nexus', 'Draft saved'))) {
				this.history = await demandHistory(this.id)
			}
		},
		async submit() {
			const ok = await this.run(async () => {
				await this.persist()
				this.setDemand(await submitDemand(this.id, this.answer.trim() || null))
				this.answer = ''
				this.commentRefresh++
				this.$emit('changed')
			}, t('nc_nexus', 'Demand submitted'))
			if (ok) {
				this.history = await demandHistory(this.id)
			}
		},
		async remove() {
			const ok = await this.run(() => deleteDemand(this.id), t('nc_nexus', 'Draft deleted'))
			if (ok) {
				this.$emit('changed')
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.editor {
	display: flex;
	flex-direction: column;
	// Gleiche Höhe wie der NcModal-Container, der sonst als Ganzes scrollen würde.
	max-height: min(90vh, 100vh - 2 * var(--header-height) - 2 * var(--body-container-margin));

	// Rechts Platz für den Schließen-Button von NcModal
	&__head {
		flex: none;
		padding: 18px 56px 16px 22px;
		border-bottom: 1px solid var(--color-border);
	}

	&__body {
		flex: 1 1 auto;
		min-height: 0;
		overflow-y: auto;
		position: relative;
		padding: 0 22px;
	}

	&__loading {
		display: flex;
		justify-content: center;
		padding: 40px 0;
	}

	&__history {
		margin: 0;
		padding: 0;
		list-style: none;

		li {
			display: grid;
			grid-template-columns: 150px minmax(0, 1fr);
			gap: 12px;
			padding: 4px 0;
			font-size: 13px;
		}
	}

	&__history-when {
		color: var(--color-text-maxcontrast);
		font-variant-numeric: tabular-nums;
	}

	&__notice {
		margin-top: 16px;
		padding: 10px 14px;
		border-radius: var(--border-radius-large, 10px);
		background: rgba(var(--color-warning-rgb), 0.12);

		p {
			margin: 4px 0 0;
			white-space: pre-wrap;
		}
	}

	&__objectives {
		margin: 0;
		padding: 0;
		list-style: none;

		li {
			padding: 2px 0;
		}

		label {
			display: flex;
			align-items: baseline;
			gap: 8px;
			cursor: pointer;
		}
	}

	&__objectives-hint {
		margin: -8px 0 8px;
	}

	&__objective-code,
	&__muted {
		color: var(--color-text-maxcontrast);
	}

	&__checks {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__check--ok {
		color: var(--color-success-text);
	}

	&__check--fail {
		color: var(--color-error-text);
	}

	&__path {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;
		align-items: center;
		margin: 0 0 10px;
		padding: 0;
		list-style: none;

		li {
			padding: 2px 10px;
			border: 1px solid var(--color-border-dark);
			border-radius: var(--border-radius-pill, 20px);
			font-size: 12px;
			font-weight: 600;
		}
	}

	&__path--done {
		background: var(--color-element-success, var(--color-success-text));
		border-color: var(--color-success) !important;
		color: var(--color-success-text-on, #fff);
	}

	&__path--current {
		background: var(--color-primary-element-light);
		border-color: var(--color-primary-element) !important;
		color: var(--color-primary-element-light-text);
	}

	&__path--open {
		color: var(--color-text-maxcontrast);
	}

	li.editor__path-set {
		border: none;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}

	&__step {
		margin: 0 0 6px;

		.nx-chip {
			margin-inline-start: 6px;
		}
	}

	&__conditions {
		margin: 10px 0;

		h4 {
			margin: 0 0 4px;
			font-size: 13px;
		}

		ul {
			margin: 0;
			padding: 0;
			list-style: none;
		}

		li {
			display: flex;
			gap: 6px;
			align-items: center;
			padding: 2px 0;
			font-size: 13px;
		}
	}

	&__condition--overdue {
		color: var(--color-error-text);
	}

	&__condition--closed {
		color: var(--color-text-maxcontrast);
	}

	&__tranches small {
		margin-inline-start: 6px;
		color: var(--color-text-maxcontrast);
	}

	&__alloc {
		margin-top: 8px;
		border-collapse: collapse;
		font-variant-numeric: tabular-nums;

		td {
			padding: 2px 16px 2px 0;
		}
	}

	&__date.nx-input.nx-input {
		width: 200px;
	}

	&__rating-actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 12px;
	}

	&__next {
		margin: 12px 0 0;
		color: var(--color-text-maxcontrast);
		font-style: italic;
	}

	&__answer {
		margin-top: 10px;
	}

	&__triage {
		margin-top: 0;
	}

	&__reason {
		margin: 12px 0 0;
		white-space: pre-wrap;
	}

	&__transition {
		margin-inline-start: 4px;
	}

	&__by {
		margin-inline-start: 4px;
		color: var(--color-text-maxcontrast);
	}

	&__changes {
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		flex: none;
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: 8px;
		padding: 12px 22px;
		border-top: 1px solid var(--color-border);
	}

	// Beschriftungen nie kürzen: lieber in eine zweite Zeile umbrechen
	&__actions,
	&__body {
		:deep(.button-vue) {
			flex: none;
			max-width: 100%;
		}

		:deep(.button-vue__text) {
			overflow: visible;
			text-overflow: clip;
		}
	}

	// Thematische Gruppen, leicht eingefärbt
	&__group {
		--nx-group-tint: var(--color-primary-element);
		margin: 16px 0;
		padding: 4px 16px 6px;
		border-radius: var(--border-radius-large, 10px);
		border-inline-start: 4px solid color-mix(in srgb, var(--nx-group-tint) 55%, transparent);
		background: color-mix(in srgb, var(--nx-group-tint) 6%, transparent);

		// ohne Inhalt (z. B. Entwurf ohne Triage, Gates und Planung) gar nicht zeigen
		&:not(:has(> .nx-section)) {
			display: none;
		}

		> .nx-section {
			border-top-color: color-mix(in srgb, var(--nx-group-tint) 18%, var(--color-border));
		}

		> .editor__group-title + .nx-section {
			border-top: none;
			padding-top: 4px;
		}

		&--economics {
			--nx-group-tint: var(--color-element-success, var(--color-success-text));
		}

		&--decision {
			--nx-group-tint: var(--color-element-warning, var(--color-warning-text));
		}

		&--exchange {
			--nx-group-tint: var(--color-text-maxcontrast);
		}
	}

	// Zwischenüberschriften in Abschnitten (z. B. Harte Zusagen) kleiner als die Gruppentitel
	&__body h4 {
		margin: 14px 0 6px;
		font-size: 14px;
		font-weight: 600;
	}

	&__group-title {
		margin: 12px 0 0;
		font-size: 16px;
		font-weight: 700;
		color: var(--color-main-text);
	}

	&__delete {
		margin-inline-end: auto;
	}
}

@media (max-width: 600px) {
	.editor .nx-field-row {
		grid-template-columns: 1fr;
	}
}
</style>

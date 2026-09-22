/**
 * The staged copy's own control for publishing at a set time (VIPPROD-1248).
 *
 * Everything this row does already exists server-side (VIPPROD-1247): the
 * route it calls, the merge that runs at the set time, every refusal. This
 * is the one surface an editor reaches it from without a terminal.
 *
 * The picker is core's own `DateTimePicker`, not the private
 * `PublishDateTimePicker` core's Publish row uses -- this plugin does not
 * reach for private APIs (see `existing-staged-copy.js`'s title lock for the
 * standing reason). What that private wrapper adds beyond the picker itself
 * -- the reset-to-immediately affordance, the timezone hint -- this row
 * builds for itself, in its own words.
 */

import { Button, DateTimePicker, Dropdown } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { dateI18n, getDate, getSettings } from '@wordpress/date';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import { context } from './context';
import { cancelSchedule, scheduleAt } from './schedule';
import { useSchedule } from './schedule-context';

/**
 * The server's `scheduledFor`, as a `Date`.
 *
 * The context ships an ISO string with an explicit `Z`
 * (`Editor_Assets::to_iso()`), which native `Date` parsing always reads as
 * the absolute instant it names, regardless of the browser's own timezone.
 * Converted once, on the way in, so every other place in this module holds
 * a `Date` and never a string that would need the same care again.
 *
 * @param {string} iso The context's `scheduledFor`, or ''.
 * @return {Date|''} The instant, or '' when nothing is scheduled.
 */
function fromContext( iso ) {
	return iso ? new Date( iso ) : '';
}

/**
 * A `DateTimePicker` value, as a `Date`.
 *
 * The picker's own `onChange` returns a timezoneless string --
 * `@wordpress/components`' `TIMEZONELESS_FORMAT` -- that is already the
 * site's wall-clock reading, because the picker builds it entirely within
 * the site's timezone. `getDate()` is `@wordpress/date`'s own way to parse
 * a string on that understanding, the same way `buildMoment()` would if
 * this were left to `dateI18n()` directly: passed a bare string with no
 * offset, `moment()` reads it as the *browser's* local time instead, which
 * silently mis-converts on any visitor whose machine is not set to the
 * site's own zone. Converting through a real `Date` here is what removes
 * that ambiguity for every later use of the value -- reopening the picker
 * on it, or formatting it as the row's own label -- since a `Date` object
 * names a fixed instant and needs no timezone guessed to read it.
 *
 * @param {string} picked What `DateTimePicker`'s `onChange` returned.
 * @return {Date} The instant it named, on the site's clock.
 */
function fromPicker( picked ) {
	return getDate( picked );
}

/**
 * Whether the site's configured time format reads a 12-hour clock.
 *
 * The same test core's own date pickers use against the same setting
 * (`getSettings().formats.time`), so this picker reads the clock face an
 * editor already sees on every other date in the admin.
 *
 * @return {boolean} True for a 12-hour clock.
 */
function is12HourTime() {
	return /a(?!\\)/i.test( getSettings().formats.time );
}

/**
 * The picker's own default when nothing is scheduled yet.
 *
 * Not "now": `DateTimePicker` needs some `currentDate` the instant it
 * renders, and this row cannot leave that as `undefined` (see
 * `ScheduleDropdown` below) -- but "now" is also a value Schedule can
 * commit unchanged, and doing so is a click that schedules a publish for
 * the next cron tick rather than opening a picker an editor is expected to
 * set. The next full hour is far enough out that committing the default
 * outright is never mistaken for "immediately", while still reading as
 * "soon" rather than as a date someone picked on purpose.
 *
 * @return {Date} The next full hour, in the browser's own clock -- the same
 *                clock `DateTimePicker` always renders in.
 */
function defaultScheduleTime() {
	const next = new Date();

	next.setMinutes( 0, 0, 0 );
	next.setHours( next.getHours() + 1 );

	return next;
}

/**
 * The picker and its actions, inside the dropdown.
 *
 * A component of its own rather than inline JSX, because it carries the one
 * piece of state that must not leak into the row: what is selected in the
 * picker before anyone has asked to schedule it. Unmounting it with the
 * dropdown -- which `Dropdown` does on every close -- is what resets that
 * choice, so reopening always starts from the committed value rather than
 * whatever was left showing last time.
 *
 * @param {Object}      props             Props.
 * @param {Date|string} props.initialDate The committed schedule, or '' when unscheduled.
 * @param {boolean}     props.scheduled   Whether a schedule is already set.
 * @param {boolean}     props.pending     Whether a request is in flight.
 * @param {Function}    props.onSchedule  Called with the picker's value on Schedule/Change.
 * @param {Function}    props.onClear     Called on Clear.
 * @return {React.ReactNode} The dropdown's content.
 */
function ScheduleDropdown( {
	initialDate,
	scheduled,
	pending,
	onSchedule,
	onClear,
} ) {
	/*
	 * Defaults to a real date, not to nothing: `DateTimePicker` needs a
	 * `currentDate` the moment it renders, and leaving this `undefined`
	 * would show a real date (moment's own default) while the Schedule
	 * button stayed disabled under it until something was touched,
	 * disagreeing with what an editor is looking at. Defaults to the next
	 * full hour rather than to `new Date()` itself, so that clicking
	 * Schedule without touching anything commits a time an editor can
	 * recognize as a default, not one that reads as "right now"
	 * (`defaultScheduleTime()`).
	 */
	const [ picked, setPicked ] = useState(
		initialDate || defaultScheduleTime()
	);

	return (
		<div className="swpub-schedule-row__content">
			<DateTimePicker
				currentDate={ picked }
				onChange={ setPicked }
				is12Hour={ is12HourTime() }
			/>
			<div className="swpub-schedule-row__actions">
				{ scheduled && (
					<Button
						variant="tertiary"
						isDestructive
						disabled={ pending }
						onClick={ onClear }
						text={ __( 'Clear', 'save-without-publish' ) }
					/>
				) }
				<Button
					variant="primary"
					isBusy={ pending }
					disabled={ pending || ! picked }
					onClick={ () => onSchedule( picked ) }
					text={
						scheduled
							? __( 'Change', 'save-without-publish' )
							: __( 'Schedule', 'save-without-publish' )
					}
				/>
			</div>
		</div>
	);
}

/**
 * Renders the row.
 *
 * @return {React.ReactNode} The row, or nothing anywhere but a staged copy
 *                           that could actually be scheduled.
 */
export function ScheduleRow() {
	const ctx = context();
	const { createErrorNotice } = useDispatch( noticesStore );
	const { schedule, setSchedule } = useSchedule();
	const [ pending, setPending ] = useState( false );

	/*
	 * Stranded, not merely unpublished: `ctx.stranded` is true the instant
	 * the published post leaves publish, before anything here has asked the
	 * server. Offering the control anyway would offer a click that the
	 * server refuses every time (`swpub_stranded`), on a fact this page
	 * already knows. Not a hook, so it is safe after the calls above.
	 */
	if ( ! ctx.isStaged || ctx.stranded ) {
		return null;
	}

	// The shared value is a bare ISO string plus its label -- the same shape
	// the page-load context ships -- so this row still works with `Date`
	// objects the way it always has, converted once on the way out.
	const scheduledFor = fromContext( schedule.scheduledFor );
	const scheduledForLabel = schedule.scheduledForLabel;

	async function commit( picked ) {
		setPending( true );

		try {
			await scheduleAt( ctx.stagedCopyId, picked );

			/*
			 * Formatted here rather than trusted from the response: the
			 * route's own `scheduledFor`/`scheduledForLocal` are bare MySQL
			 * datetimes, not run through the site's configured date and time
			 * formats the way the page's own `scheduledForLabel` was on
			 * load. `dateI18n()` against the same settings the picker itself
			 * reads is the sanctioned way to match that formatting without a
			 * round trip -- the authoritative, `wp_date()`-formatted label
			 * is what the next page load shows regardless.
			 *
			 * `fromPicker()` first, so both the stored value and the label
			 * are built from the same unambiguous instant rather than from
			 * the picker's own timezoneless string twice.
			 */
			const instant = fromPicker( picked );

			// Stored as an ISO string, the same shape the page-load context
			// ships (see `ScheduleProvider`), so `StagedNotices` -- which
			// reads this same shared value -- needs no second shape to
			// understand.
			setSchedule( {
				scheduledFor: instant.toISOString(),
				scheduledForLabel: dateI18n(
					getSettings().formats.datetime,
					instant
				),
			} );
		} catch ( error ) {
			createErrorNotice(
				error && error.message
					? error.message
					: __(
							'The publish could not be scheduled.',
							'save-without-publish'
					  ),
				{ id: 'swpub-schedule-failed', type: 'snackbar' }
			);
		} finally {
			setPending( false );
		}
	}

	async function clear() {
		setPending( true );

		try {
			await cancelSchedule( ctx.stagedCopyId );

			setSchedule( { scheduledFor: '', scheduledForLabel: '' } );
		} catch ( error ) {
			createErrorNotice(
				error && error.message
					? error.message
					: __(
							'The schedule could not be cancelled.',
							'save-without-publish'
					  ),
				{ id: 'swpub-unschedule-failed', type: 'snackbar' }
			);
		} finally {
			setPending( false );
		}
	}

	// The truth about what happens on the next Publish changes, not a
	// placeholder for a control nobody has used yet.
	const label =
		scheduledForLabel || __( 'Immediately', 'save-without-publish' );

	return (
		<PluginPostStatusInfo className="swpub-schedule-row">
			<div className="editor-post-panel__row-label">
				{ __( 'Publish at', 'save-without-publish' ) }
			</div>
			<div className="editor-post-panel__row-control">
				<Dropdown
					contentClassName="swpub-schedule-row__popover"
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							className="swpub-schedule-row__button"
							onClick={ onToggle }
							aria-expanded={ isOpen }
							variant="tertiary"
							size="compact"
							text={ label }
						/>
					) }
					renderContent={ ( { onClose } ) => (
						<ScheduleDropdown
							initialDate={ scheduledFor }
							scheduled={ !! scheduledForLabel }
							pending={ pending }
							onSchedule={ async ( picked ) => {
								await commit( picked );
								onClose();
							} }
							onClear={ async () => {
								await clear();
								onClose();
							} }
						/>
					) }
				/>
			</div>
		</PluginPostStatusInfo>
	);
}

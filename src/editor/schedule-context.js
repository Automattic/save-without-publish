/**
 * The one schedule value the row and the staged notice both read.
 *
 * `context()` is a snapshot the server attached to the page load; it never
 * changes underneath the editor, so nothing that reads it directly notices
 * when `ScheduleRow` schedules, changes, or clears a publish time in this
 * same session. Both surfaces need the live value instead of that snapshot --
 * the row already tracks it locally, and `StagedNotices` needs it too, so it
 * is lifted here once rather than duplicated.
 */

import { createContext, useContext, useState } from '@wordpress/element';

import { context } from './context';

const ScheduleContext = createContext( null );

/**
 * Wraps the plugin's rendered surfaces in the shared schedule state.
 *
 * Initialized from the page-load context, same as `ScheduleRow` was on its
 * own before this existed -- only where that state then lives has moved.
 *
 * @param {Object}          props          Props.
 * @param {React.ReactNode} props.children The wrapped surfaces.
 * @return {React.ReactNode} The provider.
 */
export function ScheduleProvider( { children } ) {
	const ctx = context();

	const [ schedule, setSchedule ] = useState( () => ( {
		scheduledFor: ctx.scheduledFor || '',
		scheduledForLabel: ctx.scheduledForLabel || '',
	} ) );

	return (
		<ScheduleContext.Provider value={ { schedule, setSchedule } }>
			{ children }
		</ScheduleContext.Provider>
	);
}

/**
 * Reads and writes the shared schedule value.
 *
 * @return {{schedule: Object, setSchedule: Function}} The current value and
 *                                                      its setter.
 */
export function useSchedule() {
	return useContext( ScheduleContext );
}

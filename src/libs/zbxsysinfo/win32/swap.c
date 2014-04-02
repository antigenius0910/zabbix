/*
** Zabbix
** Copyright (C) 2001-2014 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

#include "common.h"
#include "sysinfo.h"
#include "symbols.h"

int	SYSTEM_SWAP_SIZE(AGENT_REQUEST *request, AGENT_RESULT *result)
{
	MEMORYSTATUSEX			ms_ex;
	MEMORYSTATUS			ms;
	char				*swapdev, *mode;

	if (2 < request->nparam)
		return SYSINFO_RET_FAIL;

	swapdev = get_rparam(request, 0);
	mode = get_rparam(request, 1);

	/* only 'all' parameter supported */
	if (NULL != swapdev && '\0' != *swapdev && 0 != strcmp(swapdev, "all"))
		return SYSINFO_RET_FAIL;

	pi.cb = sizeof(PERFORMANCE_INFORMATION);

	if (NULL != zbx_GlobalMemoryStatusEx)
	{
		DWORDLONG	real_swap_total, real_swap_avail;

		ms_ex.dwLength = sizeof(MEMORYSTATUSEX);

		zbx_GlobalMemoryStatusEx(&ms_ex);

		real_swap_total = ms_ex.ullTotalPageFile > ms_ex.ullTotalPhys ?
				ms_ex.ullTotalPageFile - ms_ex.ullTotalPhys : 0;
		real_swap_avail = ms_ex.ullAvailPageFile > ms_ex.ullAvailPhys ?
				ms_ex.ullAvailPageFile - ms_ex.ullAvailPhys : 0;

		if (real_swap_avail > real_swap_total)
			real_swap_avail = real_swap_total;

		if (NULL == mode || '\0' == *mode || 0 == strcmp(mode, "total"))
			SET_UI64_RESULT(result, real_swap_total);
		else if (0 == strcmp(mode, "free"))
			SET_UI64_RESULT(result, real_swap_avail);
		else if (0 == strcmp(mode, "pfree"))
			SET_DBL_RESULT(result, real_swap_avail / real_swap_total);
		else if (0 == strcmp(mode, "used"))
			SET_UI64_RESULT(result, real_swap_total - real_swap_avail);
		else
			return SYSINFO_RET_FAIL;
	}
	else
	{
		SIZE_T		real_swap_total, real_swap_avail;

		GlobalMemoryStatus(&ms);

		real_swap_total = ms.dwTotalPageFile > ms.dwTotalPhys ?
				ms.dwTotalPageFile - ms.dwTotalPhys : 0;
		real_swap_avail = ms.dwAvailPageFile > ms.dwAvailPhys ?
				ms.dwAvailPageFile - ms.dwAvailPhys : 0;

		if (real_swap_avail > real_swap_total)
			real_swap_avail = real_swap_total;

		if (NULL == mode || '\0' == *mode || 0 == strcmp(mode, "total"))
			SET_UI64_RESULT(result, real_swap_total);
		else if (0 == strcmp(mode, "free"))
			SET_UI64_RESULT(result, real_swap_avail);
		else if (0 == strcmp(mode, "pfree"))
			SET_DBL_RESULT(result, real_swap_avail / real_swap_total);
		else if (0 == strcmp(mode, "used"))
			SET_UI64_RESULT(result, real_swap_total - real_swap_avail);
		else
			return SYSINFO_RET_FAIL;
	}

	return SYSINFO_RET_OK;
}

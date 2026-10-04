{{-- Hidden ids[] inputs for the ticked rows, for a bulk-action form. --}}
<template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
